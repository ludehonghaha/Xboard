<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Utils\Helper;
use Illuminate\Http\Request;

class AccessInviteController extends Controller
{
    public function fetch(Request $request)
    {
        $pageSize = max(10, min(100, (int) $request->input('pageSize', 20)));

        return $this->success(
            InviteCode::query()
                ->orderByDesc('id')
                ->paginate($pageSize)
        );
    }

    public function generate(Request $request)
    {
        $params = $request->validate([
            'count' => 'nullable|integer|min:1|max:100',
        ]);

        $count = (int) ($params['count'] ?? 1);
        $issuerId = (int) $request->user()->id;
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            do {
                $code = strtoupper(Helper::randomChar(12));
            } while (InviteCode::where('code', $code)->exists());

            $model = new InviteCode();
            $model->user_id = $issuerId;
            $model->code = $code;
            $model->status = InviteCode::STATUS_UNUSED;
            $model->pv = 0;
            $model->save();

            $codes[] = $code;
        }

        return $this->success($codes);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        $invite = InviteCode::find($params['id']);
        if (!$invite) {
            return $this->fail([404, '邀请码不存在']);
        }

        return $this->success((bool) $invite->delete());
    }
}
