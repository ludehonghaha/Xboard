<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;

class StatController extends Controller
{
    private $service;
    public function __construct(StatisticalService $service)
    {
        $this->service = $service;
    }
    /**
     * Xboard Lite operational dashboard.
     * One request returns the server/node/user/traffic overview used by the Lite admin UI.
     */
    public function liteDashboard()
    {
        $now = time();
        $onlineCutoff = $now - 300;
        $todayStart = strtotime('today');
        $monthStart = strtotime(date('Y-m-1'));

        $machines = ServerMachine::query()
            ->withCount('servers')
            ->orderByDesc('last_seen_at')
            ->get();

        $machineRows = $machines->take(8)->map(function (ServerMachine $machine) use ($onlineCutoff) {
            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'is_active' => (bool) $machine->is_active,
                'is_online' => (bool) $machine->is_active
                    && (int) $machine->last_seen_at >= $onlineCutoff,
                'last_seen_at' => $machine->last_seen_at,
                'servers_count' => $machine->servers_count,
                'load_status' => $machine->load_status,
            ];
        })->values();

        $nodes = Server::all();
        $onlineNodes = $nodes->filter(fn (Server $server) => (bool) $server->is_online)->count();

        $totalUsers = User::count();
        $onlineUsers = User::where('t', '>=', $now - 600)->count();
        $onlineDevices = (int) User::where('t', '>=', $now - 600)->sum('online_count');
        $activeUsers = User::where('banned', 0)
            ->whereNotNull('plan_id')
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', $now);
            })
            ->count();

        $trafficSummary = function (int $startAt) use ($now) {
            $traffic = StatServer::where('record_at', '>=', $startAt)
                ->where('record_at', '<=', $now)
                ->selectRaw('COALESCE(SUM(u), 0) as upload, COALESCE(SUM(d), 0) as download, COALESCE(SUM(u + d), 0) as total')
                ->first();

            return [
                'upload' => (int) ($traffic->upload ?? 0),
                'download' => (int) ($traffic->download ?? 0),
                'total' => (int) ($traffic->total ?? 0),
            ];
        };

        $totalTraffic = StatServer::selectRaw(
            'COALESCE(SUM(u), 0) as upload, COALESCE(SUM(d), 0) as download, COALESCE(SUM(u + d), 0) as total'
        )->first();

        $serverRank = StatServer::query()
            ->selectRaw('server_id, server_type, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->where('record_at', '>=', $todayStart)
            ->where('record_at', '<=', $now)
            ->groupBy('server_id', 'server_type')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $serverNames = Server::whereIn('id', $serverRank->pluck('server_id')->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        $serverRank = $serverRank->map(function ($item) use ($serverNames) {
            return [
                'server_id' => (int) $item->server_id,
                'name' => $serverNames[$item->server_id]->name ?? ('#' . $item->server_id),
                'type' => $item->server_type,
                'u' => (int) $item->u,
                'd' => (int) $item->d,
                'total' => (int) $item->total,
            ];
        })->values();

        $userRank = StatUser::query()
            ->selectRaw('user_id, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->where('record_at', '>=', $todayStart)
            ->where('record_at', '<=', $now)
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $rankUsers = User::whereIn('id', $userRank->pluck('user_id')->all())
            ->get(['id', 'email'])
            ->keyBy('id');

        $userRank = $userRank->map(function ($item) use ($rankUsers) {
            return [
                'user_id' => (int) $item->user_id,
                'email' => $rankUsers[$item->user_id]->email ?? ('#' . $item->user_id),
                'u' => (int) $item->u,
                'd' => (int) $item->d,
                'total' => (int) $item->total,
            ];
        })->values();

        $recentUsers = User::query()
            ->with('plan:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'email', 'plan_id', 'expired_at', 'created_at'])
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'plan' => $user->plan?->name,
                    'expired_at' => $user->expired_at,
                    'created_at' => $user->created_at,
                ];
            });

        $expiringUsers = User::query()
            ->with('plan:id,name')
            ->whereNotNull('expired_at')
            ->whereBetween('expired_at', [$now, $now + 7 * 86400])
            ->orderBy('expired_at')
            ->limit(8)
            ->get(['id', 'email', 'plan_id', 'expired_at'])
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'plan' => $user->plan?->name,
                    'expired_at' => $user->expired_at,
                ];
            });

        return $this->success([
            'machines' => [
                'total' => $machines->count(),
                'online' => $machines->filter(
                    fn (ServerMachine $machine) => (bool) $machine->is_active
                        && (int) $machine->last_seen_at >= $onlineCutoff
                )->count(),
                'items' => $machineRows,
            ],
            'nodes' => [
                'total' => $nodes->count(),
                'online' => $onlineNodes,
            ],
            'users' => [
                'total' => $totalUsers,
                'active' => $activeUsers,
                'online' => $onlineUsers,
                'online_devices' => $onlineDevices,
            ],
            'traffic' => [
                'today' => $trafficSummary($todayStart),
                'month' => $trafficSummary($monthStart),
                'total' => [
                    'upload' => (int) ($totalTraffic->upload ?? 0),
                    'download' => (int) ($totalTraffic->download ?? 0),
                    'total' => (int) ($totalTraffic->total ?? 0),
                ],
            ],
            'server_rank' => $serverRank,
            'user_rank' => $userRank,
            'recent_users' => $recentUsers,
            'expiring_users' => $expiringUsers,
            'generated_at' => $now,
        ]);
    }

    public function getOverride(Request $request)
    {
        // 获取在线节点数
        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();
        // 获取在线设备数和在线用户数
        $onlineDevices = User::where('t', '>=', time() - 600)
            ->sum('online_count');
        $onlineUsers = User::where('t', '>=', time() - 600)
            ->count();

        // 获取今日流量统计
        $todayStart = strtotime('today');
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthStart = strtotime(date('Y-m-1'));
        $monthTraffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        return [
            'data' => [
                // Commerce/support metrics are retained as zero-value compatibility keys.
                'month_income' => 0,
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => 0,
                'commission_pending_total' => 0,
                'day_income' => 0,
                'last_month_income' => 0,
                'commission_month_payout' => 0,
                'commission_last_month_payout' => 0,
                // Lite statistics
                'online_nodes' => $onlineNodes,
                'online_devices' => $onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => [],
                'summary' => [
                    'paid_total' => 0,
                    'paid_count' => 0,
                    'commission_total' => 0,
                    'commission_count' => 0,
                    'avg_paid_amount' => 0,
                    'avg_commission_amount' => 0,
                    'commission_rate' => 0,
                ],
            ],
        ];
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $pageSize = $request->input('pageSize', 10);
        $records = StatUser::orderBy('record_at', 'DESC')
            ->where('user_id', $request->input('user_id'))
            ->paginate($pageSize);

        $data = $records->items();
        return [
            'data' => $data,
            'total' => $records->total(),
        ];
    }

    public function getStatRecord(Request $request)
    {
        return [
            'data' => $this->service->getStatRecord($request->input('type'))
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        $currentMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);

        $todayStart = strtotime('today');

        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();

        $onlineDevices = User::where('t', '>=', time() - 600)
            ->sum('online_count');
        $onlineUsers = User::where('t', '>=', time() - 600)
            ->count();

        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        $monthTraffic = StatServer::where('record_at', '>=', $currentMonthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        $currentMonthNewUsers = User::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->count();

        $lastMonthNewUsers = User::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->count();

        $totalUsers = User::count();
        $activeUsers = User::where(function ($query) {
            $query->where('expired_at', '>=', time())
                ->orWhere('expired_at', NULL);
        })->count();

        $userGrowth = $lastMonthNewUsers > 0
            ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1)
            : 0;

        return [
            'data' => [
                // Compatibility keys for the upstream compiled dashboard.
                'todayIncome' => 0,
                'dayIncomeGrowth' => 0,
                'currentMonthIncome' => 0,
                'lastMonthIncome' => 0,
                'monthIncomeGrowth' => 0,
                'lastMonthIncomeGrowth' => 0,
                'currentMonthCommissionPayout' => 0,
                'lastMonthCommissionPayout' => 0,
                'commissionGrowth' => 0,
                'commissionPendingTotal' => 0,
                'ticketPendingTotal' => 0,

                // Lite operational metrics.
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $onlineUsers,
                'onlineDevices' => $onlineDevices,
                'onlineNodes' => $onlineNodes,
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}
