(() => {
  const cfg = window.XBOARD_LITE || {};
  const root = document.getElementById('app');
  const KEY = 'xboard_lite_auth';
  const GB = 1073741824;
  const state = {
    auth: localStorage.getItem(KEY) || '',
    isAdmin: false,
    user: null,
    sub: null,
    page: 'overview',
    plans: [],
    groups: [],
  };

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmtBytes = (n) => {
    n = Number(n || 0);
    const u = ['B','KB','MB','GB','TB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(n >= 100 ? 0 : n >= 10 ? 1 : 2) : Math.round(n)) + ' ' + u[i];
  };
  const fmtDate = (v) => {
    if (!v) return '不限';
    const n = Number(v);
    const d = Number.isFinite(n) ? new Date(n * 1000) : new Date(v);
    return isNaN(d) ? '-' : d.toLocaleString('zh-CN', {hour12:false});
  };
  const tsFromLocal = (v) => v ? Math.floor(new Date(v).getTime() / 1000) : null;
  const localFromTs = (v) => {
    if (!v) return '';
    const d = new Date(Number(v) * 1000);
    const z = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}T${z(d.getHours())}:${z(d.getMinutes())}`;
  };
  const toast = (msg, ok = true) => {
    const el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = `position:fixed;right:18px;bottom:18px;z-index:50;padding:11px 14px;border-radius:10px;color:#fff;background:${ok?'#166534':'#991b1b'};box-shadow:0 8px 30px rgba(0,0,0,.2)`;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2600);
  };
  async function request(path, options = {}) {
    const headers = {'Accept':'application/json', ...(options.headers || {})};
    if (options.auth !== false && state.auth) headers.Authorization = state.auth;
    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body !== 'string') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const res = await fetch(path, {...options, headers, body});
    let json = null;
    try { json = await res.json(); } catch (_) {}
    if (!res.ok || (json && json.status === 'fail')) {
      const validation = json?.errors ? Object.values(json.errors).flat()[0] : null;
      throw new Error(validation || json?.message || `请求失败 (${res.status})`);
    }
    if (json && Object.prototype.hasOwnProperty.call(json, 'status')) return json.data;
    return json;
  }
  const adminUrl = (p) => `/api/v2/${encodeURIComponent(cfg.securePath)}/${p}`;

  function modal(title, html, onReady) {
    const wrap = document.createElement('div');
    wrap.className = 'modal-backdrop';
    wrap.innerHTML = `<div class="modal"><div class="row"><h3>${esc(title)}</h3><button class="btn ghost small" data-close>关闭</button></div>${html}</div>`;
    wrap.addEventListener('click', e => { if (e.target === wrap || e.target.closest('[data-close]')) wrap.remove(); });
    document.body.appendChild(wrap);
    onReady?.(wrap.querySelector('.modal'), () => wrap.remove());
  }

  function authScreen(mode = 'login') {
    root.innerHTML = `
      <div class="auth">
        <div class="auth-card">
          <div class="brand">${esc(cfg.title || 'Xboard Lite')}</div>
          <div class="sub">一个页面，管理订阅与节点。</div>
          <div class="tabs">
            <button class="tab ${mode==='login'?'active':''}" data-mode="login">登录</button>
            <button class="tab ${mode==='register'?'active':''}" data-mode="register">邀请码注册</button>
          </div>
          <form id="auth-form">
            <div class="field"><label>邮箱</label><input name="email" type="email" autocomplete="username" required></div>
            <div class="field"><label>密码</label><input name="password" type="password" autocomplete="${mode==='login'?'current-password':'new-password'}" minlength="8" required></div>
            ${mode==='register'?'<div class="field"><label>邀请码</label><input name="invite_code" minlength="6" maxlength="32" required></div>':''}
            <button class="btn primary" type="submit">${mode==='login'?'登录':'注册并登录'}</button>
            <div id="auth-msg" class="error"></div>
          </form>
        </div>
      </div>`;
    root.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => authScreen(b.dataset.mode));
    root.querySelector('#auth-form').onsubmit = async e => {
      e.preventDefault();
      const form = Object.fromEntries(new FormData(e.currentTarget).entries());
      const msg = root.querySelector('#auth-msg');
      msg.textContent = '处理中…';
      try {
        const endpoint = mode === 'login' ? 'login' : 'register';
        const data = await request(`/api/v1/passport/auth/${endpoint}`, {method:'POST', auth:false, body:form});
        state.auth = data.auth_data;
        state.isAdmin = !!data.is_admin;
        localStorage.setItem(KEY, state.auth);
        await bootstrap();
      } catch (err) { msg.textContent = err.message; }
    };
  }

  async function bootstrap() {
    if (!state.auth) return authScreen();
    try {
      const login = await request('/api/v1/user/checkLogin');
      if (!login?.is_login) throw new Error('登录已失效');
      state.isAdmin = !!login.is_admin;
      [state.user, state.sub] = await Promise.all([
        request('/api/v1/user/info'),
        request('/api/v1/user/getSubscribe'),
      ]);
      shell();
      navigate(state.isAdmin ? 'overview' : 'subscription');
    } catch (_) {
      localStorage.removeItem(KEY);
      state.auth = '';
      authScreen();
    }
  }

  function navItems() {
    const basic = [
      ['subscription','我的订阅'],
      ['account','账号安全'],
    ];
    if (!state.isAdmin) return basic;
    return [
      ['overview','概览'],
      ['users','用户'],
      ['plans','套餐与权限'],
      ['machines','服务器'],
      ['nodes','节点'],
      ['routes','路由'],
      ['invites','邀请码'],
      ['settings','基础设置'],
      ...basic,
    ];
  }
  function shell() {
    root.innerHTML = `
      <div class="shell">
        <aside class="sidebar">
          <div class="brand">${esc(cfg.title || 'Xboard Lite')}</div>
          <nav class="nav">${navItems().map(([k,t])=>`<button data-page="${k}">${t}</button>`).join('')}</nav>
          <div class="foot">Lite v2.3 · ${esc(cfg.version || '')}</div>
        </aside>
        <main class="main">
          <header class="topbar">
            <div class="who">${esc(state.user?.email || '')}${state.isAdmin?' · 管理员':''}</div>
            <button class="btn ghost small" id="logout">退出</button>
          </header>
          <section class="content" id="content"></section>
        </main>
      </div>`;
    root.querySelectorAll('[data-page]').forEach(b => b.onclick = () => navigate(b.dataset.page));
    root.querySelector('#logout').onclick = () => {
      localStorage.removeItem(KEY); state.auth = ''; state.user = null; state.sub = null; authScreen();
    };
  }
  async function navigate(page) {
    state.page = page;
    root.querySelectorAll('[data-page]').forEach(b => b.classList.toggle('active', b.dataset.page === page));
    const c = root.querySelector('#content');
    c.innerHTML = '<div class="boot">加载中…</div>';
    try {
      const fn = {
        overview: renderOverview, subscription: renderSubscription, account: renderAccount,
        users: renderUsers, plans: renderPlans, machines: renderMachines,
        nodes: renderNodes, routes: renderRoutes, invites: renderInvites, settings: renderSettings
      }[page] || renderSubscription;
      await fn(c);
    } catch (e) {
      c.innerHTML = `<div class="card"><b>加载失败</b><div class="error">${esc(e.message)}</div></div>`;
    }
  }

  async function renderOverview(c) {
    if (!state.isAdmin) return renderSubscription(c);
    const d = await request(adminUrl('stat/liteDashboard'));
    c.innerHTML = `
      <h1 class="page-title">概览</h1>
      <div class="grid cols4">
        <div class="card metric"><div class="label">用户</div><div class="value">${d.users.total}</div><div class="muted">活跃 ${d.users.active} · 在线 ${d.users.online}</div></div>
        <div class="card metric"><div class="label">节点</div><div class="value">${d.nodes.online}/${d.nodes.total}</div><div class="muted">在线 / 总数</div></div>
        <div class="card metric"><div class="label">服务器</div><div class="value">${d.machines.online}/${d.machines.total}</div><div class="muted">在线 / 总数</div></div>
        <div class="card metric"><div class="label">今日流量</div><div class="value">${fmtBytes(d.traffic.today.total)}</div><div class="muted">本月 ${fmtBytes(d.traffic.month.total)}</div></div>
      </div>
      <div class="grid cols2" style="margin-top:14px">
        <div class="card"><b>最近用户</b><div class="stack" style="margin-top:12px">${(d.recent_users||[]).map(u=>`<div class="row"><span>${esc(u.email)}</span><span class="muted">${esc(u.plan||'未分配')}</span></div>`).join('') || '<span class="muted">暂无</span>'}</div></div>
        <div class="card"><b>7 天内到期</b><div class="stack" style="margin-top:12px">${(d.expiring_users||[]).map(u=>`<div class="row"><span>${esc(u.email)}</span><span class="muted">${fmtDate(u.expired_at)}</span></div>`).join('') || '<span class="muted">暂无</span>'}</div></div>
      </div>`;
  }

  async function refreshSelf() {
    [state.user, state.sub] = await Promise.all([request('/api/v1/user/info'), request('/api/v1/user/getSubscribe')]);
  }
  async function renderSubscription(c) {
    await refreshSelf();
    const s = state.sub || {};
    const used = Number(s.u||0)+Number(s.d||0), total = Number(s.transfer_enable||0);
    c.innerHTML = `
      <h1 class="page-title">我的订阅</h1>
      <div class="grid cols4">
        <div class="card metric"><div class="label">已用流量</div><div class="value">${fmtBytes(used)}</div></div>
        <div class="card metric"><div class="label">剩余流量</div><div class="value">${fmtBytes(Math.max(0,total-used))}</div></div>
        <div class="card metric"><div class="label">到期时间</div><div class="value" style="font-size:16px">${fmtDate(s.expired_at)}</div></div>
        <div class="card metric"><div class="label">限速 / 设备</div><div class="value" style="font-size:16px">${s.speed_limit||'不限'} Mbps · ${s.device_limit||'不限'}</div></div>
      </div>
      <div class="card" style="margin-top:14px">
        <div class="row"><b>订阅地址</b><div class="actions"><button class="btn ghost small" id="copy-sub">复制</button><button class="btn danger small" id="reset-sub">重置</button></div></div>
        <div class="codebox" style="margin-top:12px">${esc(s.subscribe_url||'')}</div>
      </div>`;
    c.querySelector('#copy-sub').onclick = async () => { await navigator.clipboard.writeText(s.subscribe_url||''); toast('已复制订阅地址'); };
    c.querySelector('#reset-sub').onclick = async () => {
      if (!confirm('重置后旧订阅地址立即失效，确定继续？')) return;
      const url = await request('/api/v1/user/resetSecurity');
      toast('订阅地址已重置'); await refreshSelf(); renderSubscription(c);
    };
  }

  async function renderAccount(c) {
    c.innerHTML = `
      <h1 class="page-title">账号安全</h1>
      <div class="card" style="max-width:560px">
        <div class="muted">当前账号：${esc(state.user?.email||'')}</div>
        <form id="pwd">
          <div class="field"><label>当前密码</label><input type="password" name="old_password" minlength="8" required></div>
          <div class="field"><label>新密码</label><input type="password" name="new_password" minlength="8" required></div>
          <div class="actions"><button class="btn primary">修改密码</button></div>
          <div id="pwd-msg"></div>
        </form>
      </div>`;
    c.querySelector('#pwd').onsubmit = async e => {
      e.preventDefault(); const msg = c.querySelector('#pwd-msg');
      try { await request('/api/v1/user/changePassword',{method:'POST',body:Object.fromEntries(new FormData(e.currentTarget).entries())}); msg.className='success'; msg.textContent='密码已修改'; e.currentTarget.reset(); }
      catch(err){ msg.className='error'; msg.textContent=err.message; }
    };
  }

  async function loadPlansGroups() {
    const [plans, groups] = await Promise.all([
      request(adminUrl('plan/fetch')),
      request(adminUrl('server/group/fetch')),
    ]);
    state.plans = plans || [];
    state.groups = groups || [];
  }

  async function renderUsers(c) {
    await loadPlansGroups();
    const page = await request(adminUrl('user/fetch?current=1&pageSize=100'));
    const users = page.data || [];
    c.innerHTML = `
      <h1 class="page-title">用户</h1>
      <div class="toolbar"><input id="user-search" placeholder="搜索邮箱"><span class="muted">共 ${page.total||users.length} 个用户</span></div>
      <div class="table-wrap"><table><thead><tr><th>邮箱</th><th>套餐</th><th>流量</th><th>到期</th><th>状态</th><th></th></tr></thead><tbody id="users-body"></tbody></table></div>`;
    const tbody = c.querySelector('#users-body');
    const paint = (keyword='') => {
      tbody.innerHTML = users.filter(u => String(u.email||'').toLowerCase().includes(keyword.toLowerCase())).map(u => {
        const used = Number(u.u||0)+Number(u.d||0);
        return `<tr>
          <td>${esc(u.email)}</td>
          <td>${esc(u.plan?.name||'未分配')}</td>
          <td>${fmtBytes(used)} / ${fmtBytes(u.transfer_enable)}</td>
          <td>${fmtDate(u.expired_at)}</td>
          <td><span class="badge ${u.banned?'bad':'good'}">${u.banned?'禁用':'正常'}</span></td>
          <td><button class="btn ghost small" data-edit-user="${u.id}">编辑</button></td>
        </tr>`;
      }).join('');
      tbody.querySelectorAll('[data-edit-user]').forEach(b => b.onclick = () => editUser(users.find(x => Number(x.id)===Number(b.dataset.editUser)), c));
    };
    paint();
    c.querySelector('#user-search').oninput = e => paint(e.target.value);
  }

  function editUser(user, c) {
    modal('编辑用户', `
      <form id="edit-user">
        <div class="field"><label>邮箱</label><input name="email" type="email" value="${esc(user.email)}"></div>
        <div class="split">
          <div class="field"><label>套餐</label><select name="plan_id"><option value="">未分配</option>${state.plans.map(p=>`<option value="${p.id}" ${Number(p.id)===Number(user.plan_id)?'selected':''}>${esc(p.name)}</option>`).join('')}</select></div>
          <div class="field"><label>流量配额 (GB)</label><input name="transfer_gb" type="number" min="0" step="1" value="${Math.round(Number(user.transfer_enable||0)/GB)}"></div>
        </div>
        <div class="split">
          <div class="field"><label>限速 Mbps（0=不限）</label><input name="speed_limit" type="number" min="0" value="${Number(user.speed_limit||0)}"></div>
          <div class="field"><label>设备数（0=不限）</label><input name="device_limit" type="number" min="0" value="${Number(user.device_limit||0)}"></div>
        </div>
        <div class="field"><label>到期时间（留空=不限）</label><input name="expired_local" type="datetime-local" value="${localFromTs(user.expired_at)}"></div>
        <div class="field"><label>备注</label><input name="remarks" value="${esc(user.remarks||'')}"></div>
        <div class="field"><label><input name="banned" type="checkbox" ${user.banned?'checked':''}> 禁用账号</label></div>
        <div class="actions"><button class="btn primary">保存</button></div>
        <div id="edit-user-msg"></div>
      </form>`, (m, close) => {
        m.querySelector('#edit-user').onsubmit = async e => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget), msg=m.querySelector('#edit-user-msg');
          const body = {
            id:Number(user.id),
            email:fd.get('email'),
            plan_id:fd.get('plan_id') ? Number(fd.get('plan_id')) : null,
            transfer_enable:Math.round(Number(fd.get('transfer_gb')||0)*GB),
            speed_limit:Number(fd.get('speed_limit')||0),
            device_limit:Number(fd.get('device_limit')||0),
            expired_at:tsFromLocal(fd.get('expired_local')),
            remarks:fd.get('remarks')||null,
            banned:fd.get('banned')==='on'
          };
          try { await request(adminUrl('user/update'),{method:'POST',body}); toast('用户已更新'); close(); renderUsers(c); }
          catch(err){ msg.className='error'; msg.textContent=err.message; }
        };
      }
    );
  }

  async function renderPlans(c) {
    await loadPlansGroups();
    c.innerHTML = `
      <div class="row"><h1 class="page-title">套餐与权限</h1><div class="actions"><button class="btn ghost small" id="new-group">新建权限组</button><button class="btn primary small" id="new-plan">新建套餐</button></div></div>
      <div class="grid cols2">
        <div class="card"><b>权限组</b><div class="stack" style="margin-top:12px">${state.groups.map(g=>`<div class="row"><span>${esc(g.name)}</span><span class="muted">${g.users_count||0} 用户 · ${g.server_count||0} 节点</span></div>`).join('')||'<span class="muted">暂无权限组</span>'}</div></div>
        <div class="card"><b>套餐</b><div class="stack" style="margin-top:12px">${state.plans.map(p=>`<div class="row"><span>${esc(p.name)}</span><div><span class="muted">${p.transfer_enable} GB · ${p.speed_limit||0} Mbps</span> <button class="btn ghost small" data-edit-plan="${p.id}">编辑</button></div></div>`).join('')||'<span class="muted">暂无套餐</span>'}</div></div>
      </div>`;
    c.querySelector('#new-group').onclick = () => {
      modal('新建权限组','<form id="group-form"><div class="field"><label>名称</label><input name="name" required></div><button class="btn primary">保存</button><div id="gm"></div></form>',(m,close)=>{
        m.querySelector('#group-form').onsubmit=async e=>{e.preventDefault();try{await request(adminUrl('server/group/save'),{method:'POST',body:Object.fromEntries(new FormData(e.currentTarget).entries())});toast('权限组已创建');close();renderPlans(c)}catch(err){m.querySelector('#gm').className='error';m.querySelector('#gm').textContent=err.message}};
      });
    };
    const openPlan = (p={}) => {
      modal(p.id?'编辑套餐':'新建套餐',`
        <form id="plan-form">
          <div class="field"><label>名称</label><input name="name" value="${esc(p.name||'')}" required></div>
          <div class="split">
            <div class="field"><label>流量配额 GB</label><input name="transfer_enable" type="number" min="1" value="${Number(p.transfer_enable||100)}" required></div>
            <div class="field"><label>权限组</label><select name="group_id"><option value="">不分组</option>${state.groups.map(g=>`<option value="${g.id}" ${Number(g.id)===Number(p.group_id)?'selected':''}>${esc(g.name)}</option>`).join('')}</select></div>
          </div>
          <div class="split">
            <div class="field"><label>限速 Mbps</label><input name="speed_limit" type="number" min="0" value="${Number(p.speed_limit||0)}"></div>
            <div class="field"><label>设备数</label><input name="device_limit" type="number" min="0" value="${Number(p.device_limit||0)}"></div>
          </div>
          <div class="actions"><button class="btn primary">保存</button></div><div id="pm"></div>
        </form>`,(m,close)=>{
          m.querySelector('#plan-form').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget);const body={id:p.id||null,name:fd.get('name'),transfer_enable:Number(fd.get('transfer_enable')),group_id:fd.get('group_id')?Number(fd.get('group_id')):null,speed_limit:Number(fd.get('speed_limit')||0),device_limit:Number(fd.get('device_limit')||0),show:true};try{await request(adminUrl('plan/save'),{method:'POST',body});toast('套餐已保存');close();renderPlans(c)}catch(err){m.querySelector('#pm').className='error';m.querySelector('#pm').textContent=err.message}};
        });
    };
    c.querySelector('#new-plan').onclick=()=>openPlan({});
    c.querySelectorAll('[data-edit-plan]').forEach(b=>b.onclick=()=>openPlan(state.plans.find(p=>Number(p.id)===Number(b.dataset.editPlan))));
  }

  async function renderMachines(c) {
    const items = await request(adminUrl('server/machine/fetch'));
    c.innerHTML = `
      <div class="row"><h1 class="page-title">服务器</h1><button class="btn primary small" id="new-machine">添加服务器</button></div>
      <div class="table-wrap"><table><thead><tr><th>名称</th><th>节点数</th><th>状态</th><th>最后在线</th><th></th></tr></thead><tbody>
      ${(items||[]).map(m=>`<tr><td>${esc(m.name)}</td><td>${m.servers_count||0}</td><td><span class="badge ${m.is_active?'good':'bad'}">${m.is_active?'启用':'停用'}</span></td><td>${fmtDate(m.last_seen_at)}</td><td><button class="btn ghost small" data-install="${m.id}">安装命令</button></td></tr>`).join('')}
      </tbody></table></div>`;
    c.querySelector('#new-machine').onclick = () => modal('添加服务器','<form id="machine-form"><div class="field"><label>名称</label><input name="name" required></div><div class="field"><label>备注</label><input name="notes"></div><button class="btn primary">创建</button><div id="mm"></div></form>',(m,close)=>{
      m.querySelector('#machine-form').onsubmit=async e=>{e.preventDefault();try{const data=await request(adminUrl('server/machine/save'),{method:'POST',body:{...Object.fromEntries(new FormData(e.currentTarget).entries()),is_active:true}});close();modal('安装命令',`<div class="codebox">${esc(data.install_command||'')}</div><div class="actions" style="margin-top:12px"><button class="btn primary" id="copy-cmd">复制</button></div>`,x=>x.querySelector('#copy-cmd').onclick=async()=>{await navigator.clipboard.writeText(data.install_command||'');toast('已复制')});renderMachines(c)}catch(err){m.querySelector('#mm').className='error';m.querySelector('#mm').textContent=err.message}};
    });
    c.querySelectorAll('[data-install]').forEach(b=>b.onclick=async()=>{try{const d=await request(adminUrl('server/machine/installCommand?id='+encodeURIComponent(b.dataset.install)));modal('安装命令',`<div class="codebox">${esc(d.command||'')}</div><div class="actions" style="margin-top:12px"><button class="btn primary" id="copy-cmd">复制</button></div>`,m=>m.querySelector('#copy-cmd').onclick=async()=>{await navigator.clipboard.writeText(d.command||'');toast('已复制')})}catch(err){toast(err.message,false)}});
  }

  async function renderNodes(c) {
    const [nodes, machines] = await Promise.all([request(adminUrl('server/manage/getNodes')),request(adminUrl('server/machine/fetch'))]);
    c.innerHTML = `
      <h1 class="page-title">节点</h1>
      <div class="muted" style="margin-bottom:12px">Lite v2 只保留节点状态、展示开关和服务器绑定；复杂协议参数后续单独做精简编辑器。</div>
      <div class="table-wrap"><table><thead><tr><th>名称</th><th>协议</th><th>地址</th><th>服务器</th><th>启用</th><th>展示</th></tr></thead><tbody>
      ${(nodes||[]).map(n=>`<tr>
        <td>${esc(n.name)}</td><td><span class="badge">${esc(n.type)}</span></td><td class="mono">${esc(n.host)}:${esc(n.port)}</td>
        <td><select data-machine-node="${n.id}"><option value="">未绑定</option>${(machines||[]).map(m=>`<option value="${m.id}" ${Number(m.id)===Number(n.machine_id)?'selected':''}>${esc(m.name)}</option>`).join('')}</select></td>
        <td><input type="checkbox" data-node-enabled="${n.id}" ${n.enabled?'checked':''}></td>
        <td><input type="checkbox" data-node-show="${n.id}" ${Number(n.show)?'checked':''}></td>
      </tr>`).join('')}
      </tbody></table></div>`;
    c.querySelectorAll('[data-node-enabled]').forEach(x=>x.onchange=()=>updateNode(x.dataset.nodeEnabled,{enabled:x.checked}));
    c.querySelectorAll('[data-node-show]').forEach(x=>x.onchange=()=>updateNode(x.dataset.nodeShow,{show:x.checked?1:0}));
    c.querySelectorAll('[data-machine-node]').forEach(x=>x.onchange=()=>updateNode(x.dataset.machineNode,{machine_id:x.value?Number(x.value):null}));
    async function updateNode(id, patch){try{await request(adminUrl('server/manage/update'),{method:'POST',body:{id:Number(id),...patch}});toast('节点已更新')}catch(err){toast(err.message,false);renderNodes(c)}}
  }

  async function renderRoutes(c) {
    const raw = await request(adminUrl('server/route/fetch'));
    const routes = raw?.data || [];
    c.innerHTML = `
      <div class="row"><h1 class="page-title">路由</h1><button class="btn primary small" id="new-route">添加路由</button></div>
      <div class="table-wrap"><table><thead><tr><th>备注</th><th>匹配</th><th>动作</th><th></th></tr></thead><tbody>
      ${routes.map(r=>`<tr><td>${esc(r.remarks)}</td><td class="mono">${esc(Array.isArray(r.match)?r.match.join(', '):r.match||'')}</td><td>${esc(r.action)} ${esc(r.action_value||'')}</td><td><button class="btn ghost small" data-edit-route="${r.id}">编辑</button></td></tr>`).join('')}
      </tbody></table></div>`;
    const openRoute = (r={}) => modal(r.id?'编辑路由':'添加路由',`
      <form id="route-form"><div class="field"><label>备注</label><input name="remarks" value="${esc(r.remarks||'')}" required></div>
      <div class="field"><label>匹配值（每行一个）</label><textarea name="match" rows="5" required>${esc(Array.isArray(r.match)?r.match.join('\n'):'')}</textarea></div>
      <div class="split"><div class="field"><label>动作</label><select name="action">${['direct','proxy','block','dns'].map(a=>`<option ${r.action===a?'selected':''}>${a}</option>`).join('')}</select></div><div class="field"><label>动作值</label><input name="action_value" value="${esc(r.action_value||'')}"></div></div>
      <button class="btn primary">保存</button><div id="rm"></div></form>`,(m,close)=>{
        m.querySelector('#route-form').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget);const body={id:r.id||null,remarks:fd.get('remarks'),match:String(fd.get('match')).split(/\r?\n/).map(x=>x.trim()).filter(Boolean),action:fd.get('action'),action_value:fd.get('action_value')||null};try{await request(adminUrl('server/route/save'),{method:'POST',body});toast('路由已保存');close();renderRoutes(c)}catch(err){m.querySelector('#rm').className='error';m.querySelector('#rm').textContent=err.message}};
      });
    c.querySelector('#new-route').onclick=()=>openRoute({});
    c.querySelectorAll('[data-edit-route]').forEach(b=>b.onclick=()=>openRoute(routes.find(r=>Number(r.id)===Number(b.dataset.editRoute))));
  }

  async function renderInvites(c) {
    const page = await request(adminUrl('access-invite/fetch?pageSize=100'));
    const items = page.data || [];
    c.innerHTML = `
      <div class="row"><h1 class="page-title">邀请码</h1><div class="actions"><input id="invite-count" type="number" min="1" max="100" value="1" style="width:72px"><button class="btn primary small" id="gen-invite">生成</button></div></div>
      <div class="table-wrap"><table><thead><tr><th>邀请码</th><th>状态</th><th>创建时间</th><th></th></tr></thead><tbody>
      ${items.map(i=>`<tr><td class="mono">${esc(i.code)}</td><td><span class="badge ${Number(i.status)===0?'good':''}">${Number(i.status)===0?'未使用':'已使用'}</span></td><td>${fmtDate(i.created_at)}</td><td>${Number(i.status)===0?`<button class="btn danger small" data-drop-invite="${i.id}">删除</button>`:''}</td></tr>`).join('')}
      </tbody></table></div>`;
    c.querySelector('#gen-invite').onclick=async()=>{try{const codes=await request(adminUrl('access-invite/generate'),{method:'POST',body:{count:Number(c.querySelector('#invite-count').value||1)}});modal('新邀请码',`<div class="codebox">${esc((codes||[]).join('\n'))}</div><div class="actions" style="margin-top:12px"><button class="btn primary" id="copy-inv">复制</button></div>`,m=>m.querySelector('#copy-inv').onclick=async()=>{await navigator.clipboard.writeText((codes||[]).join('\n'));toast('已复制')});renderInvites(c)}catch(err){toast(err.message,false)}};
    c.querySelectorAll('[data-drop-invite]').forEach(b=>b.onclick=async()=>{if(!confirm('删除这个未使用的邀请码？'))return;try{await request(adminUrl('access-invite/drop'),{method:'POST',body:{id:Number(b.dataset.dropInvite)}});renderInvites(c)}catch(err){toast(err.message,false)}});
  }

  async function renderSettings(c) {
    const all = await request(adminUrl('config/fetch'));
    const site = all.site || {}, sub = all.subscribe || {};
    c.innerHTML = `
      <h1 class="page-title">基础设置</h1>
      <div class="card" style="max-width:720px">
        <form id="settings-form">
          <div class="field"><label>站点名称</label><input name="app_name" value="${esc(site.app_name||'')}"></div>
          <div class="field"><label>站点地址</label><input name="app_url" value="${esc(site.app_url||'')}" placeholder="https://panel.example.com"></div>
          <div class="field"><label>订阅地址（可留空使用站点地址）</label><input name="subscribe_url" value="${esc(site.subscribe_url||'')}"></div>
          <div class="field"><label>订阅路径</label><input name="subscribe_path" value="${esc(sub.subscribe_path||'s')}"></div>
          <div class="actions"><button class="btn primary">保存</button></div><div id="sm"></div>
        </form>
      </div>`;
    c.querySelector('#settings-form').onsubmit=async e=>{e.preventDefault();const msg=c.querySelector('#sm');try{await request(adminUrl('config/save'),{method:'POST',body:Object.fromEntries(new FormData(e.currentTarget).entries())});msg.className='success';msg.textContent='已保存'}catch(err){msg.className='error';msg.textContent=err.message}};
  }


  // ---- Lite v2.1: restore core admin operations without restoring commerce UI ----
  const v21ProtocolTypes = ['mieru','hysteria','vless','trojan','vmess','shadowsocks','tuic','anytls','socks','naive','http'];
  const v21ProtocolDefaults = {
    mieru:{transport:'TCP',traffic_pattern:'default'},
    hysteria:{version:2,obfs:{open:false,type:'salamander',password:''},tls:{server_name:'',allow_insecure:false}},
    vless:{tls:0,network:'tcp',network_settings:{},multiplex:{enabled:false},utls:{enabled:false,fingerprint:'chrome'}},
    trojan:{tls:1,network:'tcp',network_settings:{},allow_insecure:false,multiplex:{enabled:false},utls:{enabled:false,fingerprint:'chrome'}},
    vmess:{tls:0,network:'tcp',network_settings:{},rules:[],multiplex:{enabled:false},utls:{enabled:false,fingerprint:'chrome'}},
    shadowsocks:{cipher:'2022-blake3-aes-128-gcm',obfs:'',plugin:'',plugin_opts:''},
    tuic:{version:5,congestion_control:'cubic',alpn:['h3'],udp_relay_mode:'native',tls:{server_name:'',allow_insecure:false}},
    anytls:{tls:{server_name:'',allow_insecure:false},padding_scheme:[]},
    socks:{tls:0},
    naive:{tls:1,tls_settings:{server_name:'',allow_insecure:false}},
    http:{tls:0,tls_settings:{server_name:'',allow_insecure:false}}
  };
  function v21Pretty(value) { return JSON.stringify(value ?? {}, null, 2); }
  function v21Checked(v){ return v ? 'checked' : ''; }
  function v21MultiValues(form, name){ return [...form.querySelectorAll(`[name="${name}"]:checked`)].map(x=>Number(x.value)); }

  async function renderUsers(c) {
    await loadPlansGroups();
    const page = await request(adminUrl('user/fetch?current=1&pageSize=200'));
    const users = page.data || [];
    c.innerHTML = `
      <div class="row"><h1 class="page-title">用户</h1><button class="btn primary small" id="new-user">新增用户</button></div>
      <div class="toolbar"><input id="user-search" placeholder="搜索邮箱"><span class="muted">共 ${page.total||users.length} 个用户</span></div>
      <div class="table-wrap"><table><thead><tr><th>邮箱</th><th>套餐</th><th>流量</th><th>限速/设备</th><th>到期</th><th>状态</th><th></th></tr></thead><tbody id="users-body"></tbody></table></div>`;
    const tbody = c.querySelector('#users-body');
    const paint = (keyword='') => {
      tbody.innerHTML = users.filter(u => String(u.email||'').toLowerCase().includes(keyword.toLowerCase())).map(u => {
        const used = Number(u.u||0)+Number(u.d||0);
        return `<tr>
          <td>${esc(u.email)}</td><td>${esc(u.plan?.name||'未分配')}</td>
          <td>${fmtBytes(used)} / ${fmtBytes(u.transfer_enable)}</td>
          <td>${u.speed_limit||'不限'} Mbps / ${u.device_limit||'不限'}</td>
          <td>${fmtDate(u.expired_at)}</td>
          <td><span class="badge ${u.banned?'bad':'good'}">${u.banned?'禁用':'正常'}</span></td>
          <td><div class="actions">
            <button class="btn ghost small" data-edit-user="${u.id}">编辑</button>
            <button class="btn ghost small" data-reset-user="${u.id}">重置订阅</button>
            <button class="btn ghost small" data-zero-user="${u.id}">清零流量</button>
            ${!u.is_admin?`<button class="btn danger small" data-drop-user="${u.id}">删除</button>`:''}
          </div></td></tr>`;
      }).join('');
      tbody.querySelectorAll('[data-edit-user]').forEach(b => b.onclick = () => editUser(users.find(x => Number(x.id)===Number(b.dataset.editUser)), c));
      tbody.querySelectorAll('[data-reset-user]').forEach(b => b.onclick = async()=>{if(!confirm('重置该用户订阅地址？旧地址会失效。'))return;try{await request(adminUrl('user/resetSecret'),{method:'POST',body:{id:Number(b.dataset.resetUser)}});toast('订阅地址已重置')}catch(e){toast(e.message,false)}});
      tbody.querySelectorAll('[data-zero-user]').forEach(b => b.onclick = async()=>{const u=users.find(x=>Number(x.id)===Number(b.dataset.zeroUser));if(!confirm(`清零 ${u.email} 的已用流量？`))return;try{await request(adminUrl('user/update'),{method:'POST',body:{id:u.id,u:0,d:0}});toast('流量已清零');renderUsers(c)}catch(e){toast(e.message,false)}});
      tbody.querySelectorAll('[data-drop-user]').forEach(b => b.onclick = async()=>{const u=users.find(x=>Number(x.id)===Number(b.dataset.dropUser));if(!confirm(`删除用户 ${u.email}？此操作不可撤销。`))return;try{await request(adminUrl('user/destroy'),{method:'POST',body:{id:u.id}});toast('用户已删除');renderUsers(c)}catch(e){toast(e.message,false)}});
    };
    paint();
    c.querySelector('#user-search').oninput = e => paint(e.target.value);
    c.querySelector('#new-user').onclick = () => {
      modal('新增用户',`
        <form id="new-user-form">
          <div class="field"><label>邮箱</label><input name="email" type="email" required></div>
          <div class="field"><label>密码（至少8位；留空则默认使用邮箱）</label><input name="password" type="password" minlength="8"></div>
          <div class="split"><div class="field"><label>套餐</label><select name="plan_id"><option value="">未分配</option>${state.plans.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div><div class="field"><label>到期时间</label><input name="expired_local" type="datetime-local"></div></div>
          <button class="btn primary">创建</button><div id="numsg"></div>
        </form>`,(m,close)=>{
          m.querySelector('#new-user-form').onsubmit=async e=>{
            e.preventDefault(); const fd=new FormData(e.currentTarget), email=String(fd.get('email')||'').trim(), at=email.lastIndexOf('@'), msg=m.querySelector('#numsg');
            if(at<=0){msg.className='error';msg.textContent='邮箱格式不正确';return}
            const body={email_prefix:email.slice(0,at),email_suffix:email.slice(at+1),password:fd.get('password')||null,plan_id:fd.get('plan_id')?Number(fd.get('plan_id')):null,expired_at:tsFromLocal(fd.get('expired_local'))};
            try{await request(adminUrl('user/generate'),{method:'POST',body});toast('用户已创建');close();renderUsers(c)}catch(err){msg.className='error';msg.textContent=err.message}
          };
        });
    };
  }

  function editUser(user, c) {
    modal('编辑用户', `
      <form id="edit-user">
        <div class="field"><label>邮箱</label><input name="email" type="email" value="${esc(user.email)}"></div>
        <div class="field"><label>新密码（留空不修改）</label><input name="password" type="password" minlength="8"></div>
        <div class="split"><div class="field"><label>套餐</label><select name="plan_id"><option value="">未分配</option>${state.plans.map(p=>`<option value="${p.id}" ${Number(p.id)===Number(user.plan_id)?'selected':''}>${esc(p.name)}</option>`).join('')}</select></div><div class="field"><label>流量配额 (GB)</label><input name="transfer_gb" type="number" min="0" value="${Math.round(Number(user.transfer_enable||0)/GB)}"></div></div>
        <div class="split"><div class="field"><label>限速 Mbps</label><input name="speed_limit" type="number" min="0" value="${Number(user.speed_limit||0)}"></div><div class="field"><label>设备数</label><input name="device_limit" type="number" min="0" value="${Number(user.device_limit||0)}"></div></div>
        <div class="field"><label>到期时间（留空=不限）</label><input name="expired_local" type="datetime-local" value="${localFromTs(user.expired_at)}"></div>
        <div class="field"><label>备注</label><input name="remarks" value="${esc(user.remarks||'')}"></div>
        <div class="field"><label><input name="banned" type="checkbox" ${v21Checked(user.banned)}> 禁用账号</label></div>
        <button class="btn primary">保存</button><div id="edit-user-msg"></div>
      </form>`, (m, close) => {
      m.querySelector('#edit-user').onsubmit = async e => {
        e.preventDefault(); const fd=new FormData(e.currentTarget), msg=m.querySelector('#edit-user-msg');
        const body={id:Number(user.id),email:fd.get('email'),plan_id:fd.get('plan_id')?Number(fd.get('plan_id')):null,transfer_enable:Math.round(Number(fd.get('transfer_gb')||0)*GB),speed_limit:Number(fd.get('speed_limit')||0),device_limit:Number(fd.get('device_limit')||0),expired_at:tsFromLocal(fd.get('expired_local')),remarks:fd.get('remarks')||null,banned:fd.get('banned')==='on'};
        if(fd.get('password')) body.password=fd.get('password');
        try{await request(adminUrl('user/update'),{method:'POST',body});toast('用户已更新');close();renderUsers(c)}catch(err){msg.className='error';msg.textContent=err.message}
      };
    });
  }

  async function renderPlans(c) {
    await loadPlansGroups();
    c.innerHTML = `
      <div class="row"><h1 class="page-title">套餐与权限</h1><div class="actions"><button class="btn ghost small" id="new-group">新建权限组</button><button class="btn primary small" id="new-plan">新建套餐</button></div></div>
      <div class="grid cols2">
        <div class="card"><b>权限组</b><div class="stack" style="margin-top:12px">${state.groups.map(g=>`<div class="row"><div><b>${esc(g.name)}</b><div class="muted">${g.users_count||0} 用户 · ${g.server_count||0} 节点</div></div><div class="actions"><button class="btn ghost small" data-edit-group="${g.id}">编辑</button><button class="btn danger small" data-drop-group="${g.id}">删除</button></div></div>`).join('')||'<span class="muted">暂无权限组</span>'}</div></div>
        <div class="card"><b>套餐</b><div class="stack" style="margin-top:12px">${state.plans.map(p=>`<div class="row"><div><b>${esc(p.name)}</b><div class="muted">${p.transfer_enable} GB · ${p.speed_limit||0} Mbps · ${p.device_limit||0} 设备</div></div><div class="actions"><button class="btn ghost small" data-edit-plan="${p.id}">编辑</button><button class="btn danger small" data-drop-plan="${p.id}">删除</button></div></div>`).join('')||'<span class="muted">暂无套餐</span>'}</div></div>
      </div>`;
    const openGroup=(g={})=>modal(g.id?'编辑权限组':'新建权限组',`<form id="group-form"><div class="field"><label>名称</label><input name="name" value="${esc(g.name||'')}" required></div><button class="btn primary">保存</button><div id="gm"></div></form>`,(m,close)=>{m.querySelector('#group-form').onsubmit=async e=>{e.preventDefault();try{await request(adminUrl('server/group/save'),{method:'POST',body:{id:g.id||null,name:new FormData(e.currentTarget).get('name')}});toast('权限组已保存');close();renderPlans(c)}catch(err){m.querySelector('#gm').className='error';m.querySelector('#gm').textContent=err.message}}});
    const openPlan = (p={}) => modal(p.id?'编辑套餐':'新建套餐',`
      <form id="plan-form">
        <div class="field"><label>名称</label><input name="name" value="${esc(p.name||'')}" required></div>
        <div class="field"><label>说明</label><textarea name="content" rows="3">${esc(p.content||'')}</textarea></div>
        <div class="split"><div class="field"><label>流量配额 GB</label><input name="transfer_enable" type="number" min="1" value="${Number(p.transfer_enable||100)}" required></div><div class="field"><label>权限组</label><select name="group_id"><option value="">不分组</option>${state.groups.map(g=>`<option value="${g.id}" ${Number(g.id)===Number(p.group_id)?'selected':''}>${esc(g.name)}</option>`).join('')}</select></div></div>
        <div class="split"><div class="field"><label>限速 Mbps</label><input name="speed_limit" type="number" min="0" value="${Number(p.speed_limit||0)}"></div><div class="field"><label>设备数</label><input name="device_limit" type="number" min="0" value="${Number(p.device_limit||0)}"></div></div>
        <div class="field"><label><input type="checkbox" name="show" ${v21Checked(p.show!==false)}> 对用户显示</label></div>
        <button class="btn primary">保存</button><div id="pm"></div>
      </form>`,(m,close)=>{m.querySelector('#plan-form').onsubmit=async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),body={id:p.id||null,name:fd.get('name'),content:fd.get('content')||null,transfer_enable:Number(fd.get('transfer_enable')),group_id:fd.get('group_id')?Number(fd.get('group_id')):null,speed_limit:Number(fd.get('speed_limit')||0),device_limit:Number(fd.get('device_limit')||0),show:fd.get('show')==='on'};try{await request(adminUrl('plan/save'),{method:'POST',body});toast('套餐已保存');close();renderPlans(c)}catch(err){m.querySelector('#pm').className='error';m.querySelector('#pm').textContent=err.message}}});
    c.querySelector('#new-group').onclick=()=>openGroup({});
    c.querySelector('#new-plan').onclick=()=>openPlan({});
    c.querySelectorAll('[data-edit-group]').forEach(b=>b.onclick=()=>openGroup(state.groups.find(g=>Number(g.id)===Number(b.dataset.editGroup))));
    c.querySelectorAll('[data-drop-group]').forEach(b=>b.onclick=async()=>{if(!confirm('删除这个权限组？只有未被使用时才能删除。'))return;try{await request(adminUrl('server/group/drop'),{method:'POST',body:{id:Number(b.dataset.dropGroup)}});toast('权限组已删除');renderPlans(c)}catch(e){toast(e.message,false)}});
    c.querySelectorAll('[data-edit-plan]').forEach(b=>b.onclick=()=>openPlan(state.plans.find(p=>Number(p.id)===Number(b.dataset.editPlan))));
    c.querySelectorAll('[data-drop-plan]').forEach(b=>b.onclick=async()=>{if(!confirm('删除这个套餐？'))return;try{await request(adminUrl('plan/drop'),{method:'POST',body:{id:Number(b.dataset.dropPlan)}});toast('套餐已删除');renderPlans(c)}catch(e){toast(e.message,false)}});
  }

  async function renderMachines(c) {
    const items = await request(adminUrl('server/machine/fetch'));
    let rows = '';
    (items || []).forEach(function(m) {
      rows += '<tr>' +
        '<td>' + esc(m.name) + '</td>' +
        '<td>' + (m.servers_count || 0) + '</td>' +
        '<td><span class="badge ' + (m.is_active ? 'good' : 'bad') + '">' + (m.is_active ? '启用' : '停用') + '</span></td>' +
        '<td>' + fmtDate(m.last_seen_at) + '</td>' +
        '<td>' + esc(m.notes || '') + '</td>' +
        '<td><div class="actions">' +
          '<button class="btn ghost small" data-edit-machine="' + m.id + '">编辑</button>' +
          '<button class="btn ghost small" data-install="' + m.id + '">安装命令</button>' +
          '<button class="btn danger small" data-drop-machine="' + m.id + '">删除</button>' +
        '</div></td></tr>';
    });
    c.innerHTML =
      '<div class="row"><h1 class="page-title">服务器</h1><button class="btn primary small" id="new-machine">添加服务器</button></div>' +
      '<div class="table-wrap"><table><thead><tr><th>名称</th><th>节点数</th><th>状态</th><th>最后在线</th><th>备注</th><th></th></tr></thead><tbody>' +
      rows + '</tbody></table></div>';

    function openMachine(m) {
      m = m || {};
      const checked = (m.id ? m.is_active : true) ? 'checked' : '';
      modal(m.id ? '编辑服务器' : '添加服务器',
        '<form id="machine-form">' +
          '<div class="field"><label>名称</label><input name="name" value="' + esc(m.name || '') + '" required></div>' +
          '<div class="field"><label>备注</label><textarea name="notes" rows="3">' + esc(m.notes || '') + '</textarea></div>' +
          '<div class="field"><label><input type="checkbox" name="is_active" ' + checked + '> 启用</label></div>' +
          '<button class="btn primary">保存</button><div id="mm"></div>' +
        '</form>',
        function(box, close) {
          box.querySelector('#machine-form').onsubmit = async function(e) {
            e.preventDefault();
            const fd = new FormData(e.currentTarget);
            try {
              const data = await request(adminUrl('server/machine/save'), {
                method: 'POST',
                body: {
                  id: m.id || null,
                  name: fd.get('name'),
                  notes: fd.get('notes') || null,
                  is_active: fd.get('is_active') === 'on'
                }
              });
              toast('服务器已保存');
              close();
              if (!m.id && data && data.install_command) {
                modal('安装命令', '<div class="codebox">' + esc(data.install_command) + '</div>');
              }
              renderMachines(c);
            } catch (err) {
              box.querySelector('#mm').className = 'error';
              box.querySelector('#mm').textContent = err.message;
            }
          };
        }
      );
    }

    c.querySelector('#new-machine').onclick = function() { openMachine({}); };
    c.querySelectorAll('[data-edit-machine]').forEach(function(b) {
      b.onclick = function() {
        openMachine(items.find(function(m) { return Number(m.id) === Number(b.dataset.editMachine); }));
      };
    });
    c.querySelectorAll('[data-install]').forEach(function(b) {
      b.onclick = async function() {
        try {
          const d = await request(adminUrl('server/machine/installCommand?id=' + encodeURIComponent(b.dataset.install)));
          modal('安装命令', '<div class="codebox">' + esc(d.command || '') + '</div>');
        } catch (err) { toast(err.message, false); }
      };
    });
    c.querySelectorAll('[data-drop-machine]').forEach(function(b) {
      b.onclick = async function() {
        const m = items.find(function(x) { return Number(x.id) === Number(b.dataset.dropMachine); });
        if (!confirm('删除服务器 ' + m.name + '？关联节点会自动解除绑定。')) return;
        try {
          await request(adminUrl('server/machine/drop'), {method:'POST', body:{id:m.id}});
          toast('服务器已删除');
          renderMachines(c);
        } catch (err) { toast(err.message, false); }
      };
    });
  }

  async function renderNodes(c) {
    const result = await Promise.all([
      request(adminUrl('server/manage/getNodes')),
      request(adminUrl('server/machine/fetch')),
      request(adminUrl('server/group/fetch')),
      request(adminUrl('server/route/fetch'))
    ]);
    const nodes = result[0] || [];
    const machines = result[1] || [];
    const groups = result[2] || [];
    const routes = (result[3] && result[3].data) || [];

    let rows = '';
    nodes.forEach(function(n) {
      const machine = machines.find(function(m) { return Number(m.id) === Number(n.machine_id); });
      const groupNames = (n.groups || []).map(function(g) { return esc(g.name); }).join(', ') || '-';
      rows += '<tr>' +
        '<td>' + esc(n.name) + '</td>' +
        '<td><span class="badge">' + esc(n.type) + '</span></td>' +
        '<td class="mono">' + esc(n.host) + ':' + esc(n.port) + '</td>' +
        '<td>' + esc(machine ? machine.name : '未绑定') + '</td>' +
        '<td>' + groupNames + '</td>' +
        '<td><input type="checkbox" data-node-enabled="' + n.id + '" ' + (n.enabled ? 'checked' : '') + '></td>' +
        '<td><input type="checkbox" data-node-show="' + n.id + '" ' + (Number(n.show) ? 'checked' : '') + '></td>' +
        '<td><div class="actions">' +
          '<button class="btn ghost small" data-edit-node="' + n.id + '">编辑</button>' +
          '<button class="btn ghost small" data-copy-node="' + n.id + '">复制</button>' +
          '<button class="btn ghost small" data-reset-node="' + n.id + '">清零流量</button>' +
          '<button class="btn danger small" data-drop-node="' + n.id + '">删除</button>' +
        '</div></td></tr>';
    });

    c.innerHTML =
      '<div class="row"><h1 class="page-title">节点</h1><div class="actions"><button class="btn primary small" id="quick-deploy-node">一键部署协议</button><button class="btn ghost small" id="new-node">手工添加</button></div></div>' +
      '<div class="muted" style="margin-bottom:12px">一键部署会自动生成端口与协议默认参数，并下发给绑定的 Machine Agent；Reality / ECH 等高级配置仍可手工编辑。</div>' +
      '<div class="table-wrap"><table><thead><tr><th>名称</th><th>协议</th><th>地址</th><th>服务器</th><th>权限组</th><th>启用</th><th>展示</th><th></th></tr></thead><tbody>' +
      rows + '</tbody></table></div>';

    function openNode(n) {
      n = n || {};
      const type = n.type || 'mieru';
      const proto = n.id ? (n.protocol_settings || {}) : (v21ProtocolDefaults[type] || {});
      let typeOptions = '';
      v21ProtocolTypes.forEach(function(t) {
        typeOptions += '<option value="' + t + '" ' + (t === type ? 'selected' : '') + '>' + t + '</option>';
      });
      let machineOptions = '<option value="">未绑定</option>';
      machines.forEach(function(m) {
        machineOptions += '<option value="' + m.id + '" ' + (Number(m.id) === Number(n.machine_id) ? 'selected' : '') + '>' + esc(m.name) + '</option>';
      });
      const selectedGroups = (n.group_ids || []).map(Number);
      let groupChecks = '';
      groups.forEach(function(g) {
        groupChecks += '<label class="badge"><input type="checkbox" name="group_ids" value="' + g.id + '" ' +
          (selectedGroups.includes(Number(g.id)) ? 'checked' : '') + '> ' + esc(g.name) + '</label>';
      });
      if (!groupChecks) groupChecks = '<span class="muted">暂无权限组</span>';
      const selectedRoutes = (n.route_ids || []).map(Number);
      let routeChecks = '';
      routes.forEach(function(r) {
        routeChecks += '<label class="badge"><input type="checkbox" name="route_ids" value="' + r.id + '" ' +
          (selectedRoutes.includes(Number(r.id)) ? 'checked' : '') + '> ' + esc(r.remarks) + '</label>';
      });
      if (!routeChecks) routeChecks = '<span class="muted">暂无路由</span>';

      const html =
        '<form id="node-form">' +
          '<div class="split">' +
            '<div class="field"><label>名称</label><input name="name" value="' + esc(n.name || '') + '" required></div>' +
            '<div class="field"><label>协议</label><select name="type" ' + (n.id ? 'disabled' : '') + '>' + typeOptions + '</select></div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>连接地址</label><input name="host" value="' + esc(n.host || '') + '" required></div>' +
            '<div class="field"><label>连接端口</label><input name="port" value="' + esc(n.port || '') + '" required></div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>后端服务端口</label><input name="server_port" value="' + esc(n.server_port || n.port || '') + '" required></div>' +
            '<div class="field"><label>倍率</label><input name="rate" type="number" step="0.1" min="0" value="' + Number(n.rate == null ? 1 : n.rate) + '" required></div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>服务器</label><select name="machine_id">' + machineOptions + '</select></div>' +
            '<div class="field"><label>节点流量上限 GB（0=不限）</label><input name="transfer_gb" type="number" min="0" value="' + Math.round(Number(n.transfer_enable || 0) / GB) + '"></div>' +
          '</div>' +
          '<div class="field"><label>权限组</label><div class="actions">' + groupChecks + '</div></div>' +
          '<div class="field"><label>路由</label><div class="actions">' + routeChecks + '</div></div>' +
          '<div class="split">' +
            '<label class="field"><span>启用</span><input type="checkbox" name="enabled" ' + ((n.id ? n.enabled : true) ? 'checked' : '') + '></label>' +
            '<label class="field"><span>对用户展示</span><input type="checkbox" name="show" ' + ((n.id ? Number(n.show) : true) ? 'checked' : '') + '></label>' +
          '</div>' +
          '<div class="field"><label>标签（逗号分隔）</label><input name="tags" value="' + esc(Array.isArray(n.tags) ? n.tags.join(',') : (n.tags || '')) + '"></div>' +
          '<div class="field"><label>协议高级 JSON</label><textarea name="protocol_settings" rows="14" class="mono">' + esc(v21Pretty(proto)) + '</textarea>' +
            '<span class="muted">Reality / TLS / ECH / Multiplex / Mieru / Hysteria2 等参数都保留在这里。</span></div>' +
          '<div class="actions"><button class="btn primary">保存节点</button><button type="button" class="btn ghost" id="proto-default">载入协议默认值</button></div>' +
          '<div id="nm"></div>' +
        '</form>';

      modal(n.id ? '编辑节点' : '添加节点', html, function(box, close) {
        const form = box.querySelector('#node-form');
        function loadDefault() {
          const t = form.querySelector('[name=type]').value;
          form.querySelector('[name=protocol_settings]').value = v21Pretty(v21ProtocolDefaults[t] || {});
        }
        box.querySelector('#proto-default').onclick = loadDefault;
        if (!n.id) form.querySelector('[name=type]').onchange = loadDefault;

        form.onsubmit = async function(e) {
          e.preventDefault();
          const fd = new FormData(form);
          const msg = box.querySelector('#nm');
          let settings;
          try {
            settings = JSON.parse(fd.get('protocol_settings') || '{}');
          } catch (_) {
            msg.className = 'error';
            msg.textContent = '协议高级 JSON 格式错误';
            return;
          }
          const groupIds = Array.from(form.querySelectorAll('[name=group_ids]:checked')).map(function(x) { return Number(x.value); });
          const routeIds = Array.from(form.querySelectorAll('[name=route_ids]:checked')).map(function(x) { return Number(x.value); });
          const body = {
            id: n.id || null,
            type: n.id ? n.type : fd.get('type'),
            name: fd.get('name'),
            host: fd.get('host'),
            port: fd.get('port'),
            server_port: fd.get('server_port'),
            rate: Number(fd.get('rate') || 1),
            machine_id: fd.get('machine_id') ? Number(fd.get('machine_id')) : null,
            group_ids: groupIds,
            route_ids: routeIds,
            enabled: fd.get('enabled') === 'on',
            show: fd.get('show') === 'on' ? 1 : 0,
            tags: String(fd.get('tags') || '').split(',').map(function(x) { return x.trim(); }).filter(Boolean),
            transfer_enable: Math.round(Number(fd.get('transfer_gb') || 0) * GB),
            protocol_settings: settings
          };
          try {
            await request(adminUrl('server/manage/save'), {method:'POST', body:body});
            toast('节点已保存');
            close();
            renderNodes(c);
          } catch (err) {
            msg.className = 'error';
            msg.textContent = err.message;
          }
        };
      });
    }

    function openQuickDeploy() {
      const usableMachines = machines.filter(function(m) { return m.is_active; });
      if (!usableMachines.length) {
        toast('请先添加并启用一台服务器', false);
        return;
      }

      let machineOptions = '';
      usableMachines.forEach(function(m) {
        const status = m.is_online ? '在线' : (m.last_seen_at ? '离线' : '未接入');
        machineOptions += '<option value="' + m.id + '">' + esc(m.name) + ' · ' + status + '</option>';
      });

      const protocolOptions = [
        ['mieru','Mieru · TCP'],
        ['shadowsocks','Shadowsocks · 2022'],
        ['hysteria','Hysteria2 · 自签 TLS'],
        ['vless','VLESS · TCP'],
        ['vmess','VMess · TCP'],
        ['trojan','Trojan · 自签 TLS'],
        ['tuic','TUIC · 自签 TLS'],
        ['anytls','AnyTLS · 自签 TLS']
      ].map(function(x) {
        return '<option value="' + x[0] + '">' + x[1] + '</option>';
      }).join('');

      let groupChecks = '';
      groups.forEach(function(g) {
        groupChecks += '<label class="badge"><input type="checkbox" name="quick_group_ids" value="' + g.id + '"> ' + esc(g.name) + '</label>';
      });
      if (!groupChecks) groupChecks = '<span class="muted">暂无权限组，将不限制权限组</span>';

      let routeChecks = '';
      routes.forEach(function(r) {
        routeChecks += '<label class="badge"><input type="checkbox" name="quick_route_ids" value="' + r.id + '"> ' + esc(r.remarks) + '</label>';
      });
      if (!routeChecks) routeChecks = '<span class="muted">暂无路由</span>';

      const html =
        '<form id="quick-deploy-form">' +
          '<div class="card" style="margin-bottom:12px">' +
            '<b>一键部署流程</b><div class="muted" style="margin-top:6px">保存后自动创建节点，并通知 Machine Agent 立即发现和启动服务。</div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>服务器</label><select name="machine_id">' + machineOptions + '</select></div>' +
            '<div class="field"><label>协议模板</label><select name="protocol">' + protocolOptions + '</select></div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>节点名称</label><input name="name" placeholder="例如 SG-Mieru" required></div>' +
            '<div class="field"><label>对外连接地址</label><input name="host" placeholder="服务器公网 IP 或域名" required></div>' +
          '</div>' +
          '<div class="split">' +
            '<div class="field"><label>端口</label><input name="port" type="number" min="1" max="65535" placeholder="留空自动分配 20000-59999"></div>' +
            '<div class="field" id="quick-tls-wrap" style="display:none"><label>TLS SNI / 证书名称</label><input name="tls_domain" value="node.local"><span class="muted">HY2/TUIC/Trojan/AnyTLS 使用自签证书，客户端会自动允许不安全证书。</span></div>' +
          '</div>' +
          '<div class="field"><label>权限组</label><div class="actions">' + groupChecks + '</div></div>' +
          '<div class="field"><label>路由</label><div class="actions">' + routeChecks + '</div></div>' +
          '<div class="field"><label><input type="checkbox" name="show" checked> 立即对用户展示</label></div>' +
          '<div class="card" id="quick-summary" style="margin:12px 0"></div>' +
          '<div class="actions"><button class="btn primary">立即部署</button><button type="button" class="btn ghost" id="quick-cancel">取消</button></div>' +
          '<div id="quick-msg"></div>' +
        '</form>';

      modal('一键部署协议', html, function(box, close) {
        box.classList.add('wide');
        const form = box.querySelector('#quick-deploy-form');
        const proto = form.querySelector('[name=protocol]');
        const machine = form.querySelector('[name=machine_id]');
        const name = form.querySelector('[name=name]');
        const tlsWrap = box.querySelector('#quick-tls-wrap');
        const summary = box.querySelector('#quick-summary');

        function updateQuickUi() {
          const p = proto.value;
          const m = usableMachines.find(function(x){return Number(x.id)===Number(machine.value);});
          const tlsNeeded = ['hysteria','trojan','tuic','anytls'].includes(p);
          tlsWrap.style.display = tlsNeeded ? '' : 'none';
          if (!name.value.trim()) {
            const label = p === 'hysteria' ? 'HY2' : p.toUpperCase();
            name.placeholder = (m ? m.name : 'Node') + '-' + label;
          }
          const status = m ? (m.is_online ? '在线，可立即下发' : (m.last_seen_at ? '离线，创建后待 Agent 上线自动同步' : 'Agent 未接入，先完成 Agent 安装')) : '';
          summary.innerHTML =
            '<div><b>模板：</b>' + esc(proto.options[proto.selectedIndex].text) + '</div>' +
            '<div style="margin-top:6px"><b>服务器：</b>' + esc(m ? m.name : '') + ' · ' + esc(status) + '</div>' +
            '<div class="muted" style="margin-top:6px">端口留空会由面板自动分配；创建后仍可进入“编辑”调整高级协议参数。</div>';
        }

        proto.onchange = updateQuickUi;
        machine.onchange = updateQuickUi;
        updateQuickUi();
        box.querySelector('#quick-cancel').onclick = close;

        form.onsubmit = async function(e) {
          e.preventDefault();
          const fd = new FormData(form);
          const msg = box.querySelector('#quick-msg');
          const selectedMachine = usableMachines.find(function(x){return Number(x.id)===Number(fd.get('machine_id'));});
          let nodeName = String(fd.get('name') || '').trim();
          if (!nodeName) {
            const p = String(fd.get('protocol'));
            nodeName = (selectedMachine ? selectedMachine.name : 'Node') + '-' + (p === 'hysteria' ? 'HY2' : p.toUpperCase());
          }
          const groupIds = Array.from(form.querySelectorAll('[name=quick_group_ids]:checked')).map(function(x){return Number(x.value);});
          const routeIds = Array.from(form.querySelectorAll('[name=quick_route_ids]:checked')).map(function(x){return Number(x.value);});
          const body = {
            machine_id: Number(fd.get('machine_id')),
            protocol: fd.get('protocol'),
            name: nodeName,
            host: String(fd.get('host') || '').trim(),
            port: fd.get('port') ? Number(fd.get('port')) : null,
            tls_domain: fd.get('tls_domain') || null,
            show: fd.get('show') === 'on',
            group_ids: groupIds,
            route_ids: routeIds
          };

          msg.className = 'muted';
          msg.textContent = '正在创建节点并下发到 Agent…';
          try {
            const data = await request(adminUrl('server/manage/quickDeploy'), {method:'POST', body:body});
            const online = selectedMachine && selectedMachine.is_online;
            close();
            modal('部署已创建',
              '<div class="card"><div><b>' + esc(data.name || nodeName) + '</b></div>' +
              '<div class="muted" style="margin-top:8px">协议：' + esc(data.type || body.protocol) + '</div>' +
              '<div class="muted">地址：' + esc(data.host || body.host) + ':' + esc(data.port || '') + '</div>' +
              '<div class="muted">服务器：' + esc(selectedMachine ? selectedMachine.name : '') + '</div></div>' +
              '<div class="' + (online ? 'success' : 'muted') + '" style="margin-top:12px">' +
                (online ? 'Machine Agent 在线：已发送节点发现通知，服务将自动启动。' : '节点已创建；Agent 上线后会自动发现并启动。') +
              '</div>');
            toast('一键部署节点已创建');
            renderNodes(c);
          } catch (err) {
            msg.className = 'error';
            msg.textContent = err.message;
          }
        };
      });
    }

    async function patchNode(id, patch) {
      try {
        await request(adminUrl('server/manage/update'), {method:'POST', body:Object.assign({id:Number(id)}, patch)});
        toast('节点已更新');
      } catch (err) {
        toast(err.message, false);
        renderNodes(c);
      }
    }

    c.querySelector('#quick-deploy-node').onclick = openQuickDeploy;
    c.querySelector('#new-node').onclick = function() { openNode({}); };
    c.querySelectorAll('[data-edit-node]').forEach(function(b) {
      b.onclick = function() {
        openNode(nodes.find(function(n) { return Number(n.id) === Number(b.dataset.editNode); }));
      };
    });
    c.querySelectorAll('[data-node-enabled]').forEach(function(x) {
      x.onchange = function() { patchNode(x.dataset.nodeEnabled, {enabled:x.checked}); };
    });
    c.querySelectorAll('[data-node-show]').forEach(function(x) {
      x.onchange = function() { patchNode(x.dataset.nodeShow, {show:x.checked ? 1 : 0}); };
    });
    c.querySelectorAll('[data-copy-node]').forEach(function(b) {
      b.onclick = async function() {
        try {
          await request(adminUrl('server/manage/copy'), {method:'POST', body:{id:Number(b.dataset.copyNode)}});
          toast('节点已复制（默认隐藏）');
          renderNodes(c);
        } catch (err) { toast(err.message, false); }
      };
    });
    c.querySelectorAll('[data-reset-node]').forEach(function(b) {
      b.onclick = async function() {
        if (!confirm('清零该节点流量统计？')) return;
        try {
          await request(adminUrl('server/manage/resetTraffic'), {method:'POST', body:{id:Number(b.dataset.resetNode)}});
          toast('节点流量已清零');
          renderNodes(c);
        } catch (err) { toast(err.message, false); }
      };
    });
    c.querySelectorAll('[data-drop-node]').forEach(function(b) {
      b.onclick = async function() {
        const n = nodes.find(function(x) { return Number(x.id) === Number(b.dataset.dropNode); });
        if (!confirm('删除节点 ' + n.name + '？')) return;
        try {
          await request(adminUrl('server/manage/drop'), {method:'POST', body:{id:n.id}});
          toast('节点已删除');
          renderNodes(c);
        } catch (err) { toast(err.message, false); }
      };
    });
  }

  async function renderRoutes(c) {
    const raw = await request(adminUrl('server/route/fetch'));
    const routes = (raw && raw.data) || [];
    let rows = '';
    routes.forEach(function(r) {
      const matchText = Array.isArray(r.match) ? r.match.join(', ') : (r.match || '');
      rows += '<tr><td>' + esc(r.remarks) + '</td><td class="mono">' + esc(matchText) + '</td><td>' +
        esc(r.action) + ' ' + esc(r.action_value || '') + '</td><td><div class="actions">' +
        '<button class="btn ghost small" data-edit-route="' + r.id + '">编辑</button>' +
        '<button class="btn danger small" data-drop-route="' + r.id + '">删除</button>' +
        '</div></td></tr>';
    });
    c.innerHTML =
      '<div class="row"><h1 class="page-title">路由</h1><button class="btn primary small" id="new-route">添加路由</button></div>' +
      '<div class="table-wrap"><table><thead><tr><th>备注</th><th>匹配</th><th>动作</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>';

    function openRoute(r) {
      r = r || {};
      const matchValue = Array.isArray(r.match) ? r.match.join('\n') : '';
      let actionOptions = '';
      ['direct','proxy','block','dns'].forEach(function(a) {
        actionOptions += '<option value="' + a + '" ' + (r.action === a ? 'selected' : '') + '>' + a + '</option>';
      });
      modal(r.id ? '编辑路由' : '添加路由',
        '<form id="route-form">' +
          '<div class="field"><label>备注</label><input name="remarks" value="' + esc(r.remarks || '') + '" required></div>' +
          '<div class="field"><label>匹配值（每行一个）</label><textarea name="match" rows="7" required>' + esc(matchValue) + '</textarea></div>' +
          '<div class="split"><div class="field"><label>动作</label><select name="action">' + actionOptions + '</select></div>' +
          '<div class="field"><label>动作值</label><input name="action_value" value="' + esc(r.action_value || '') + '"></div></div>' +
          '<button class="btn primary">保存</button><div id="rm"></div>' +
        '</form>',
        function(box, close) {
          box.querySelector('#route-form').onsubmit = async function(e) {
            e.preventDefault();
            const fd = new FormData(e.currentTarget);
            const body = {
              id: r.id || null,
              remarks: fd.get('remarks'),
              match: String(fd.get('match')).split(/\r?\n/).map(function(x){return x.trim();}).filter(Boolean),
              action: fd.get('action'),
              action_value: fd.get('action_value') || null
            };
            try {
              await request(adminUrl('server/route/save'), {method:'POST', body:body});
              toast('路由已保存');
              close();
              renderRoutes(c);
            } catch (err) {
              box.querySelector('#rm').className = 'error';
              box.querySelector('#rm').textContent = err.message;
            }
          };
        }
      );
    }
    c.querySelector('#new-route').onclick = function(){openRoute({});};
    c.querySelectorAll('[data-edit-route]').forEach(function(b){
      b.onclick = function(){openRoute(routes.find(function(r){return Number(r.id)===Number(b.dataset.editRoute);}));};
    });
    c.querySelectorAll('[data-drop-route]').forEach(function(b){
      b.onclick = async function(){
        if(!confirm('删除这个路由规则？')) return;
        try{
          await request(adminUrl('server/route/drop'), {method:'POST', body:{id:Number(b.dataset.dropRoute)}});
          toast('路由已删除');
          renderRoutes(c);
        }catch(err){toast(err.message,false);}
      };
    });
  }

  async function renderSettings(c) {
    const all = await request(adminUrl('config/fetch'));
    const site = all.site || {};
    const sub = all.subscribe || {};
    const server = all.server || {};
    const safe = all.safe || {};
    const tpl = all.subscribe_template || {};

    c.innerHTML =
      '<h1 class="page-title">系统设置</h1>' +
      '<div class="tabs" id="setting-tabs">' +
        '<button class="tab active" data-stab="site">站点</button>' +
        '<button class="tab" data-stab="subscribe">订阅</button>' +
        '<button class="tab" data-stab="server">节点服务</button>' +
        '<button class="tab" data-stab="safe">安全</button>' +
        '<button class="tab" data-stab="templates">订阅模板</button>' +
      '</div><div id="setting-body"></div>';
    const body = c.querySelector('#setting-body');

    function checkbox(name, label, checked) {
      return '<label class="field"><span>' + label + '</span><input type="checkbox" name="' + name + '" ' + (checked ? 'checked' : '') + '></label>';
    }

    function pane(key) {
      if (key === 'site') {
        return '<form class="card" id="settings-form">' +
          '<div class="field"><label>站点名称</label><input name="app_name" value="' + esc(site.app_name || '') + '"></div>' +
          '<div class="field"><label>站点描述</label><input name="app_description" value="' + esc(site.app_description || '') + '"></div>' +
          '<div class="field"><label>站点地址</label><input name="app_url" value="' + esc(site.app_url || '') + '"></div>' +
          '<div class="field"><label>订阅地址</label><input name="subscribe_url" value="' + esc(site.subscribe_url || '') + '"></div>' +
          '<div class="split">' + checkbox('force_https','强制 HTTPS',site.force_https) + checkbox('stop_register','停止注册',site.stop_register) + '</div>' +
          '<button class="btn primary">保存</button><div id="sm"></div></form>';
      }
      if (key === 'subscribe') {
        return '<form class="card" id="settings-form">' +
          '<div class="field"><label>订阅路径</label><input name="subscribe_path" value="' + esc(sub.subscribe_path || 's') + '"></div>' +
          '<div class="field"><label>默认流量重置</label><select name="reset_traffic_method">' +
            '<option value="0" ' + (Number(sub.reset_traffic_method)===0?'selected':'') + '>每月1号</option>' +
            '<option value="1" ' + (Number(sub.reset_traffic_method)===1?'selected':'') + '>按月</option>' +
            '<option value="2" ' + (Number(sub.reset_traffic_method)===2?'selected':'') + '>不重置</option>' +
          '</select></div>' +
          '<div class="split">' + checkbox('show_info_to_server_enable','订阅显示流量信息',sub.show_info_to_server_enable) +
            checkbox('show_protocol_to_server_enable','订阅显示协议信息',sub.show_protocol_to_server_enable) + '</div>' +
          '<div class="split">' + checkbox('default_remind_expire','默认到期提醒',sub.default_remind_expire) +
            checkbox('default_remind_traffic','默认流量提醒',sub.default_remind_traffic) + '</div>' +
          '<button class="btn primary">保存</button><div id="sm"></div></form>';
      }
      if (key === 'server') {
        return '<form class="card" id="settings-form">' +
          '<div class="field"><label>节点通信 Token</label><input name="server_token" value="' + esc(server.server_token || '') + '"></div>' +
          '<div class="split"><div class="field"><label>拉取间隔（秒）</label><input name="server_pull_interval" type="number" value="' + Number(server.server_pull_interval || 60) + '"></div>' +
          '<div class="field"><label>推送间隔（秒）</label><input name="server_push_interval" type="number" value="' + Number(server.server_push_interval || 60) + '"></div></div>' +
          '<div class="field"><label>设备限制模式</label><select name="device_limit_mode">' +
            '<option value="0" ' + (Number(server.device_limit_mode)===0?'selected':'') + '>宽松</option>' +
            '<option value="1" ' + (Number(server.device_limit_mode)===1?'selected':'') + '>严格</option></select></div>' +
          checkbox('server_ws_enable','启用 WebSocket 节点同步',server.server_ws_enable) +
          '<div class="field"><label>WebSocket 外部地址（留空自动）</label><input name="server_ws_url" value="' + esc(server.server_ws_url || '') + '"></div>' +
          '<button class="btn primary">保存</button><div id="sm"></div></form>';
      }
      if (key === 'safe') {
        return '<form class="card" id="settings-form">' +
          checkbox('safe_mode_enable','安全模式',safe.safe_mode_enable) +
          '<div class="field"><label>后台安全路径</label><input name="secure_path" value="' + esc(safe.secure_path || '') + '"></div>' +
          '<div class="split"><div class="field"><label>单 IP 注册次数</label><input name="register_limit_count" type="number" value="' + Number(safe.register_limit_count || 3) + '"></div>' +
          '<div class="field"><label>注册限制周期（分钟）</label><input name="register_limit_expire" type="number" value="' + Number(safe.register_limit_expire || 60) + '"></div></div>' +
          checkbox('register_limit_by_ip_enable','启用 IP 注册限制',safe.register_limit_by_ip_enable) +
          '<div class="split"><div class="field"><label>密码错误次数</label><input name="password_limit_count" type="number" value="' + Number(safe.password_limit_count || 5) + '"></div>' +
          '<div class="field"><label>锁定周期（分钟）</label><input name="password_limit_expire" type="number" value="' + Number(safe.password_limit_expire || 60) + '"></div></div>' +
          checkbox('password_limit_enable','启用密码错误限制',safe.password_limit_enable) +
          '<button class="btn primary">保存</button><div id="sm"></div></form>';
      }
      return '<form class="card" id="settings-form">' +
        '<div class="field"><label>Clash Meta 模板</label><textarea class="mono" rows="12" name="subscribe_template_clashmeta">' + esc(tpl.subscribe_template_clashmeta || '') + '</textarea></div>' +
        '<div class="field"><label>Sing-box 模板</label><textarea class="mono" rows="12" name="subscribe_template_singbox">' + esc(tpl.subscribe_template_singbox || '') + '</textarea></div>' +
        '<div class="field"><label>Surge 模板</label><textarea class="mono" rows="8" name="subscribe_template_surge">' + esc(tpl.subscribe_template_surge || '') + '</textarea></div>' +
        '<button class="btn primary">保存模板</button><div id="sm"></div></form>';
    }

    function show(key) {
      c.querySelectorAll('[data-stab]').forEach(function(x){x.classList.toggle('active',x.dataset.stab===key);});
      body.innerHTML = pane(key);
      const form = body.querySelector('#settings-form');
      form.onsubmit = async function(e) {
        e.preventDefault();
        const msg = body.querySelector('#sm');
        const fd = new FormData(form);
        const obj = {};
        fd.forEach(function(v,k){obj[k]=v;});
        form.querySelectorAll('input[type=checkbox]').forEach(function(x){obj[x.name]=x.checked;});
        ['force_https','stop_register'].forEach(function(k){if(k in obj)obj[k]=obj[k]?1:0;});
        ['reset_traffic_method','server_pull_interval','server_push_interval','device_limit_mode','register_limit_count','register_limit_expire','password_limit_count','password_limit_expire'].forEach(function(k){if(k in obj)obj[k]=Number(obj[k]);});
        try{
          await request(adminUrl('config/save'), {method:'POST', body:obj});
          msg.className='success';
          msg.textContent='已保存';
          toast('设置已保存');
        }catch(err){
          msg.className='error';
          msg.textContent=err.message;
        }
      };
    }
    c.querySelectorAll('[data-stab]').forEach(function(x){x.onclick=function(){show(x.dataset.stab);};});
    show('site');
  }


  function machineStatusInfo(m) {
    if (!m.is_active) return {text:'已停用', cls:'bad'};
    if (!m.last_seen_at) return {text:'未接入 Agent', cls:'warn'};
    if (m.is_online) return {text:'在线', cls:'good'};
    return {text:'离线', cls:'bad'};
  }

  function machinePct(used, total) {
    used = Number(used || 0);
    total = Number(total || 0);
    if (!total) return 0;
    return Math.max(0, Math.min(100, Math.round(used * 100 / total)));
  }

  function machineLastSeen(ts) {
    if (!ts) return '从未接入';
    return fmtDate(ts);
  }

  function machineRate(v) {
    v = Number(v || 0);
    if (!v) return '0 B/s';
    return fmtBytes(v) + '/s';
  }

  function machineMetric(label, value, sub, pct) {
    const bar = pct == null ? '' :
      '<div style="height:6px;background:var(--line,#e5e7eb);border-radius:6px;overflow:hidden;margin-top:6px">' +
      '<div style="height:100%;width:' + pct + '%;background:currentColor;opacity:.55"></div></div>';
    return '<div class="card" style="padding:12px;min-width:0">' +
      '<div class="muted" style="font-size:12px">' + label + '</div>' +
      '<div style="font-size:18px;font-weight:700;margin-top:4px">' + value + '</div>' +
      '<div class="muted" style="font-size:12px;margin-top:2px">' + (sub || '') + '</div>' + bar +
      '</div>';
  }

  function machineSpark(history, getter) {
    const values = (history || []).map(getter).map(Number).filter(Number.isFinite);
    if (values.length < 2) return '<div class="muted">暂无历史数据</div>';
    const max = Math.max.apply(null, values.concat([1]));
    const w = 420, h = 86, pad = 4;
    const points = values.map(function(v, i) {
      const x = pad + i * (w - pad * 2) / Math.max(1, values.length - 1);
      const y = h - pad - (v / max) * (h - pad * 2);
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" style="width:100%;height:86px;display:block">' +
      '<polyline points="' + points + '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"></polyline>' +
      '</svg>';
  }

  async function renderMachines(c) {
    const items = await request(adminUrl('server/machine/fetch'));
    let rows = '';

    (items || []).forEach(function(m) {
      const s = machineStatusInfo(m);
      const load = m.load_status || {};
      const mem = load.mem || {};
      const disk = load.disk || {};
      const net = load.net || {};
      const memPct = machinePct(mem.used, mem.total);
      const diskPct = machinePct(disk.used, disk.total);
      const cpuText = load.cpu == null ? '—' : Number(load.cpu).toFixed(1) + '%';
      const memText = mem.total ? (memPct + '%') : '—';
      const diskText = disk.total ? (diskPct + '%') : '—';
      const netText = load.net ? ('↓ ' + machineRate(net.in_speed) + ' / ↑ ' + machineRate(net.out_speed)) : '—';

      rows += '<tr>' +
        '<td><b>' + esc(m.name) + '</b><div class="muted" style="font-size:12px">' + esc(m.notes || '') + '</div></td>' +
        '<td><span class="badge ' + s.cls + '">' + s.text + '</span></td>' +
        '<td>' + cpuText + '</td>' +
        '<td>' + memText + '</td>' +
        '<td>' + diskText + '</td>' +
        '<td style="white-space:nowrap">' + netText + '</td>' +
        '<td>' + (m.servers_count || 0) + '</td>' +
        '<td style="white-space:nowrap">' + machineLastSeen(m.last_seen_at) + '</td>' +
        '<td><div class="actions">' +
          '<button class="btn primary small" data-machine-detail="' + m.id + '">详情</button>' +
          '<button class="btn ghost small" data-machine-sync="' + m.id + '" ' + (!m.is_online ? 'disabled' : '') + '>同步</button>' +
          '<button class="btn ghost small" data-machine-install="' + m.id + '">Agent</button>' +
          '<button class="btn ghost small" data-machine-edit="' + m.id + '">编辑</button>' +
        '</div></td>' +
      '</tr>';
    });

    c.innerHTML =
      '<div class="row"><div><h1 class="page-title" style="margin-bottom:4px">服务器</h1>' +
      '<div class="muted">机器状态、Agent 心跳、负载、网络、绑定节点与同步管理。</div></div>' +
      '<button class="btn primary small" id="new-machine">添加服务器</button></div>' +
      '<div class="table-wrap" style="margin-top:16px"><table><thead><tr>' +
      '<th>服务器</th><th>状态</th><th>CPU</th><th>内存</th><th>磁盘</th><th>实时网络</th><th>节点</th><th>最后心跳</th><th></th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>';

    function openMachineEditor(m) {
      m = m || {};
      modal(m.id ? '编辑服务器' : '添加服务器',
        '<form id="machine-form">' +
          '<div class="field"><label>名称</label><input name="name" value="' + esc(m.name || '') + '" required></div>' +
          '<div class="field"><label>备注</label><textarea name="notes" rows="3">' + esc(m.notes || '') + '</textarea></div>' +
          '<div class="field"><label><input type="checkbox" name="is_active" ' + ((m.id ? m.is_active : true) ? 'checked' : '') + '> 启用服务器</label></div>' +
          '<button class="btn primary">保存</button><div id="machine-msg"></div>' +
        '</form>',
        function(box, close) {
          box.querySelector('#machine-form').onsubmit = async function(e) {
            e.preventDefault();
            const fd = new FormData(e.currentTarget);
            try {
              const data = await request(adminUrl('server/machine/save'), {
                method:'POST',
                body:{
                  id:m.id || null,
                  name:fd.get('name'),
                  notes:fd.get('notes') || null,
                  is_active:fd.get('is_active') === 'on'
                }
              });
              toast('服务器已保存');
              close();
              if (!m.id && data && data.install_command) {
                modal('安装 Agent', '<div class="muted" style="margin-bottom:10px">在目标服务器以 root/sudo 执行：</div><div class="codebox">' + esc(data.install_command) + '</div>');
              }
              renderMachines(c);
            } catch (err) {
              const msg = box.querySelector('#machine-msg');
              msg.className = 'error';
              msg.textContent = err.message;
            }
          };
        }
      );
    }

    async function showInstall(m) {
      try {
        const d = await request(adminUrl('server/machine/installCommand?id=' + encodeURIComponent(m.id)));
        modal('安装 / 重装 Agent · ' + esc(m.name),
          '<div class="muted" style="margin-bottom:10px">在目标 VPS 上执行。安装成功后通常会在几分钟内出现心跳和负载。</div>' +
          '<div class="codebox" id="agent-command">' + esc(d.command || '') + '</div>' +
          '<div class="actions" style="margin-top:12px"><button class="btn primary" id="copy-agent-command">复制命令</button></div>',
          function(box) {
            box.querySelector('#copy-agent-command').onclick = async function() {
              await navigator.clipboard.writeText(d.command || '');
              toast('安装命令已复制');
            };
          }
        );
      } catch (err) { toast(err.message, false); }
    }

    async function syncMachine(m) {
      try {
        await request(adminUrl('server/machine/sync'), {method:'POST', body:{id:m.id}});
        toast('已向 ' + m.name + ' 推送节点与配置同步');
      } catch (err) { toast(err.message, false); }
    }

    async function showMachineDetail(m) {
      let nodes = [], history = [];
      try {
        const data = await Promise.all([
          request(adminUrl('server/machine/nodes?machine_id=' + encodeURIComponent(m.id))),
          request(adminUrl('server/machine/history?machine_id=' + encodeURIComponent(m.id) + '&range_hours=24&limit=240'))
        ]);
        nodes = data[0] || [];
        history = data[1] || [];
      } catch (err) {
        toast(err.message, false);
      }

      const s = machineStatusInfo(m);
      const load = m.load_status || {};
      const mem = load.mem || {};
      const disk = load.disk || {};
      const net = load.net || {};
      const memPct = machinePct(mem.used, mem.total);
      const diskPct = machinePct(disk.used, disk.total);

      let nodeRows = '';
      nodes.forEach(function(n) {
        nodeRows += '<tr><td>' + esc(n.name) + '</td><td><span class="badge">' + esc(n.type) + '</span></td><td class="mono">' + esc(n.host) + ':' + esc(n.port) + '</td><td>' + (n.enabled ? '启用' : '停用') + '</td><td>' + (Number(n.show) ? '展示' : '隐藏') + '</td></tr>';
      });
      if (!nodeRows) nodeRows = '<tr><td colspan="5" class="muted">此服务器暂未绑定节点</td></tr>';

      const noAgent = !m.last_seen_at ?
        '<div class="card" style="border-style:dashed;margin-bottom:14px"><b>Agent 尚未接入</b><div class="muted" style="margin-top:6px">当前只有服务器记录，没有心跳或负载数据。点击“安装 / 重装 Agent”并在目标 VPS 执行安装命令。</div></div>' : '';

      const html =
        '<div class="row" style="margin-bottom:14px"><div><h2 style="margin:0">' + esc(m.name) + '</h2><div class="muted">' + esc(m.notes || '无备注') + '</div></div><span class="badge ' + s.cls + '">' + s.text + '</span></div>' +
        noAgent +
        '<div class="grid cols2" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:10px">' +
          machineMetric('CPU', load.cpu == null ? '—' : Number(load.cpu).toFixed(1) + '%', '当前负载', load.cpu == null ? null : Number(load.cpu)) +
          machineMetric('内存', mem.total ? memPct + '%' : '—', mem.total ? (fmtBytes(mem.used) + ' / ' + fmtBytes(mem.total)) : '暂无数据', mem.total ? memPct : null) +
          machineMetric('磁盘', disk.total ? diskPct + '%' : '—', disk.total ? (fmtBytes(disk.used) + ' / ' + fmtBytes(disk.total)) : '暂无数据', disk.total ? diskPct : null) +
          machineMetric('实时网络', load.net ? ('↓ ' + machineRate(net.in_speed)) : '—', load.net ? ('↑ ' + machineRate(net.out_speed)) : '暂无数据', null) +
        '</div>' +
        '<div class="card" style="margin-top:12px"><div class="row"><b>连接状态</b><span class="muted">最后心跳：' + machineLastSeen(m.last_seen_at) + '</span></div>' +
          '<div class="muted" style="margin-top:8px">服务器 ID：' + m.id + ' · 已绑定节点：' + (m.servers_count || 0) + '</div></div>' +
        '<div class="grid cols2" style="margin-top:12px;gap:12px">' +
          '<div class="card"><div class="row"><b>CPU · 24h</b><span class="muted">' + history.length + ' 点</span></div>' + machineSpark(history, function(x){return x.cpu || 0;}) + '</div>' +
          '<div class="card"><div class="row"><b>内存 · 24h</b><span class="muted">使用率</span></div>' + machineSpark(history, function(x){return machinePct(x.mem_used,x.mem_total);}) + '</div>' +
        '</div>' +
        '<div class="card" style="margin-top:12px"><div class="row"><b>绑定节点</b><span class="muted">' + nodes.length + ' 个</span></div>' +
          '<div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>名称</th><th>协议</th><th>地址</th><th>启用</th><th>展示</th></tr></thead><tbody>' + nodeRows + '</tbody></table></div></div>' +
        '<div class="actions" style="margin-top:14px;flex-wrap:wrap">' +
          '<button class="btn primary" id="machine-detail-sync" ' + (!m.is_online ? 'disabled' : '') + '>立即同步</button>' +
          '<button class="btn ghost" id="machine-detail-agent">安装 / 重装 Agent</button>' +
          '<button class="btn ghost" id="machine-detail-token">查看 Token</button>' +
          '<button class="btn ghost" id="machine-detail-edit">编辑服务器</button>' +
          '<button class="btn danger" id="machine-detail-drop">删除服务器</button>' +
        '</div>';

      modal('服务器详情', html, function(box, close) {
        box.classList.add('wide');
        box.querySelector('#machine-detail-sync').onclick = function(){syncMachine(m);};
        box.querySelector('#machine-detail-agent').onclick = function(){showInstall(m);};
        box.querySelector('#machine-detail-edit').onclick = function(){close();openMachineEditor(m);};
        box.querySelector('#machine-detail-token').onclick = async function() {
          try {
            const d = await request(adminUrl('server/machine/getToken?id=' + encodeURIComponent(m.id)));
            modal('Agent Token · ' + esc(m.name),
              '<div class="codebox" id="machine-token">' + esc(d.token || '') + '</div>' +
              '<div class="actions" style="margin-top:12px"><button class="btn ghost" id="copy-machine-token">复制</button><button class="btn danger" id="reset-machine-token">重置 Token</button></div>',
              function(tb) {
                tb.querySelector('#copy-machine-token').onclick = async function(){await navigator.clipboard.writeText(d.token || '');toast('Token 已复制');};
                tb.querySelector('#reset-machine-token').onclick = async function(){
                  if(!confirm('重置后旧 Agent Token 会立即失效，需要重新配置/安装 Agent。继续？')) return;
                  try {
                    const x = await request(adminUrl('server/machine/resetToken'), {method:'POST',body:{id:m.id}});
                    tb.querySelector('#machine-token').textContent = x.token || '';
                    toast('Token 已重置');
                  } catch(err){toast(err.message,false);}
                };
              }
            );
          } catch (err) { toast(err.message, false); }
        };
        box.querySelector('#machine-detail-drop').onclick = async function() {
          if(!confirm('删除服务器 ' + m.name + '？关联节点会解除绑定。')) return;
          try {
            await request(adminUrl('server/machine/drop'), {method:'POST',body:{id:m.id}});
            toast('服务器已删除');
            close();
            renderMachines(c);
          } catch(err){toast(err.message,false);}
        };
      });
    }

    c.querySelector('#new-machine').onclick = function(){openMachineEditor({});};
    c.querySelectorAll('[data-machine-detail]').forEach(function(b){
      b.onclick = function(){showMachineDetail(items.find(function(m){return Number(m.id)===Number(b.dataset.machineDetail);}));};
    });
    c.querySelectorAll('[data-machine-sync]').forEach(function(b){
      b.onclick = function(){syncMachine(items.find(function(m){return Number(m.id)===Number(b.dataset.machineSync);}));};
    });
    c.querySelectorAll('[data-machine-install]').forEach(function(b){
      b.onclick = function(){showInstall(items.find(function(m){return Number(m.id)===Number(b.dataset.machineInstall);}));};
    });
    c.querySelectorAll('[data-machine-edit]').forEach(function(b){
      b.onclick = function(){openMachineEditor(items.find(function(m){return Number(m.id)===Number(b.dataset.machineEdit);}));};
    });
  }

  bootstrap();
})();
