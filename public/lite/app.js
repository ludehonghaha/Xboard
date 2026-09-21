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
          <div class="foot">Lite v2 · ${esc(cfg.version || '')}</div>
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

  bootstrap();
})();
