// ===================== MulaEarn — shared JS =====================

function showToast(msg){
  const toast = document.getElementById('toast');
  if(!toast) return;
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(window.__toastTimer);
  window.__toastTimer = setTimeout(()=> toast.classList.remove('show'), 3200);
}

/* ---------- registration page: referral banner ---------- */
function initReferral(){
  const params = new URLSearchParams(window.location.search);
  const ref = params.get('ref');
  const card = document.getElementById('refCard');
  if(!card) return;
  if(ref && /^[a-zA-Z0-9_-]{2,30}$/.test(ref)){
    document.getElementById('refName').textContent = ref;
    card.style.display = 'block';
  } else {
    card.style.display = 'none';
  }
}

function initRegisterForm(){
  const form = document.getElementById('registerForm');
  if(!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const pw = document.getElementById('password').value;
    const pw2 = document.getElementById('confirmPassword').value;

    if(pw !== pw2){
      showToast("Passwords don't match — check and try again.");
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const payload = {
      username: document.getElementById('username').value.trim(),
      email: document.getElementById('email').value.trim(),
      phone: document.getElementById('countryCode').value + document.getElementById('mobile').value.trim(),
      country_code: document.getElementById('country').value,
      password: pw,
      confirm_password: pw2,
      ref: params.get('ref') || '',
    };

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating account…';

    const { data } = await Api.post('/api/register.php', payload);

    if(data.success){
      showToast('Account created — welcome to MulaEarn.');
      window.location.href = 'dashboard.html';
    } else {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Create Account';
      const message = (data.errors && data.errors.join(' ')) || data.error || 'Could not create your account. Please check your details.';
      showToast(message);
    }
  });
}

function initLoginForm(){
  const form = document.getElementById('loginForm');
  if(!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const errorBox = document.getElementById('loginError');
    errorBox.style.display = 'none';

    const payload = {
      identifier: document.getElementById('identifier').value.trim(),
      password: document.getElementById('password').value,
    };

    submitBtn.disabled = true;
    submitBtn.textContent = 'Signing in…';

    const { data } = await Api.post('/api/login.php', payload);

    if(data.success){
      const params = new URLSearchParams(window.location.search);
      window.location.href = params.get('next') || 'dashboard.html';
    } else {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Sign In';
      errorBox.textContent = data.error || 'Could not sign in. Check your details and try again.';
      errorBox.style.display = 'block';
    }
  });
}

/* ---------- affiliate marketplace (real data) ---------- */
const CATEGORY_ICONS = {
  Technology: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 9h18M7 14h4"/></svg>',
  Finance: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>',
  Beauty: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-4.5-7-10a7 7 0 0 1 14 0c0 5.5-7 10-7 10Z"/><circle cx="12" cy="11" r="2.4"/></svg>',
  Fitness: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 6.5 12 3l5.5 3.5v9L12 19l-5.5-3.5Z"/><path d="M12 3v16"/></svg>',
  Travel: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2-7 4 14 2-7h6"/></svg>',
  Home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17V9l8-5 8 5v8"/><path d="M9 21v-6h6v6"/></svg>',
};
const DEFAULT_CATEGORY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/></svg>';

let CAMPAIGNS = [];       // populated from /api/affiliate/campaigns.php
let CAMPAIGN_AGGREGATES = { clicks: {}, conversions: {} };

function money2(n){
  return 'KSh ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderCampaigns(filter){
  const grid = document.getElementById('campaignGrid');
  if(!grid) return;
  const list = filter && filter !== 'All' ? CAMPAIGNS.filter(c => c.category === filter) : CAMPAIGNS;

  if(list.length === 0){
    grid.innerHTML = '<p class="text-faint">No campaigns in this category right now.</p>';
    return;
  }

  grid.innerHTML = list.map(c => {
    const agg = { clicks: CAMPAIGN_AGGREGATES.clicks[c.id] || 0, conversions: CAMPAIGN_AGGREGATES.conversions[c.id] || 0 };
    const endsLabel = c.ends_at ? new Date(c.ends_at).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' }) : 'Ongoing';
    return `
    <div class="campaign-card">
      <div class="campaign-photo" style="background-image:url('https://picsum.photos/seed/mulaearn-campaign-${c.id}/400/220');"></div>
      <div class="campaign-body">
      <div class="campaign-cat-icon">${CATEGORY_ICONS[c.category] || DEFAULT_CATEGORY_ICON}</div>
      <div class="campaign-top">
        <div>
          <div class="campaign-brand">${c.brand_name}</div>
          <h3>${c.title}</h3>
          <div class="campaign-cat">${c.category}</div>
        </div>
        <span class="status-badge ${c.status === 'active' ? 'active' : 'ending'}">${c.status === 'active' ? 'Active' : 'Ending soon'}</span>
      </div>
      <p class="desc">${c.description || ''}</p>
      <div class="campaign-commission">${money2(c.commission_amount)} <span style="font-size:12px; color:var(--ink-faint); font-weight:500;">per conversion</span></div>
      <div class="campaign-reqs">${c.requirements ? 'Requirements: ' + c.requirements : ''}</div>
      <div class="campaign-meta">
        <div>Clicks<br><b>${agg.clicks.toLocaleString()}</b></div>
        <div>Conversions<br><b>${agg.conversions}</b></div>
        <div>Ends<br><b>${endsLabel}</b></div>
      </div>
      <button class="btn btn-primary btn-block" style="margin-top:16px;" onclick="openPromote(${c.id})">Promote</button>
      </div>
    </div>
  `;
  }).join('');
}

async function loadCampaigns(){
  const grid = document.getElementById('campaignGrid');
  if(!grid) return;
  const { data } = await Api.get('/api/affiliate/campaigns.php');
  if(data.success){
    CAMPAIGNS = data.campaigns || [];
    CAMPAIGN_AGGREGATES = data.aggregates || { clicks: {}, conversions: {} };
    const activeFilterBtn = document.querySelector('#filterRow button.active');
    renderCampaigns(activeFilterBtn ? activeFilterBtn.dataset.cat : 'All');
  } else {
    grid.innerHTML = '<p class="text-faint">Could not load campaigns right now.</p>';
  }
}

function initCampaignFilters(){
  const row = document.getElementById('filterRow');
  if(!row) return;
  row.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if(!btn) return;
    row.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderCampaigns(btn.dataset.cat);
  });
  loadCampaigns();
}

async function openPromote(campaignId){
  const user = await Api.me();
  if(!user.user){
    showToast('Log in to generate your tracking link.');
    window.location.href = '/login.html?next=' + encodeURIComponent('/affiliate.html');
    return;
  }

  const campaign = CAMPAIGNS.find(c => c.id === campaignId);
  const modal = document.getElementById('promoteModal');
  document.getElementById('promoteCampaignName').textContent = campaign ? campaign.title : 'Campaign';
  document.getElementById('trackingLink').value = 'Generating your link…';
  modal.classList.add('open');

  const { data } = await Api.post('/api/affiliate/promote.php', { campaign_id: campaignId });

  if(!data.success){
    document.getElementById('trackingLink').value = '';
    showToast(data.error || 'Could not generate your tracking link.');
    modal.classList.remove('open');
    return;
  }

  const link = data.tracking_url;
  document.getElementById('trackingLink').value = link;

  const waUrl = `https://wa.me/?text=${encodeURIComponent('Check this out: ' + link)}`;
  const fbUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(link)}`;
  const xUrl = `https://twitter.com/intent/tweet?url=${encodeURIComponent(link)}&text=${encodeURIComponent('Check this out')}`;
  document.getElementById('shareWA').href = waUrl;
  document.getElementById('shareFB').href = fbUrl;
  document.getElementById('shareX').href = xUrl;

  const qrEl = document.getElementById('qrcode');
  qrEl.innerHTML = '';
  if(window.QRCode){
    new QRCode(qrEl, { text: link, width:120, height:120, colorDark:'#16234A', colorLight:'#ffffff' });
  }
}

function closePromote(){
  document.getElementById('promoteModal').classList.remove('open');
}

function copyTrackingLink(){
  const input = document.getElementById('trackingLink');
  input.select();
  navigator.clipboard?.writeText(input.value);
  showToast('Link copied to clipboard.');
}

/* ---------- affiliate performance summary (real data) ---------- */
async function initAffiliatePage(){
  const clicksEl = document.getElementById('affStatClicks');
  if(!clicksEl) return; // not the affiliate page

  const { user } = await Api.me();
  const subEl = document.querySelector('.aff-hero .section-sub');

  if(!user){
    if(subEl) subEl.textContent = 'Log in to see your real, live affiliate performance.';
    return;
  }

  const { data } = await Api.get('/api/affiliate/stats.php');
  if(!data.success) return;
  const s = data.stats;

  document.getElementById('affStatClicks').textContent = s.total_clicks.toLocaleString();
  document.getElementById('affStatUnique').textContent = s.unique_clicks.toLocaleString();
  document.getElementById('affStatConversions').textContent = s.conversions;
  document.getElementById('affStatRate').textContent = s.conversion_rate + '%';
  document.getElementById('affStatPending').textContent = money2(s.pending_commission);
  document.getElementById('affStatApproved').textContent = money2(s.approved_commission);
  document.getElementById('affStatRejected').textContent = s.rejected_conversions;
  document.getElementById('affStatTotal').textContent = money2(s.total_earnings);
}

function copyReferralLink(){
  const input = document.getElementById('myReferralLink');
  if(!input) return;
  input.select();
  navigator.clipboard?.writeText(input.value);
  showToast('Referral link copied to clipboard.');
}

/* ---------- affiliate performance chart ---------- */
const CHART_DATA = {
  '7d':  { labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], clicks:[42,58,49,71,66,90,77], conversions:[3,5,4,6,5,8,7] },
  '30d': { labels:['W1','W2','W3','W4'], clicks:[320,410,390,455], conversions:[24,31,28,35] },
  '3m':  { labels:['Jun','Jul','Aug'], clicks:[1240,1510,1380], conversions:[92,108,101] },
  'all': { labels:['Q1','Q2','Q3','Q4'], clicks:[2100,2600,3040,2890], conversions:[150,190,220,205] },
};

function initAffiliateChart(){
  const canvas = document.getElementById('affChart');
  if(!canvas || !window.Chart) return;
  let chart;
  const ctx = canvas.getContext('2d');

  function draw(range){
    const d = CHART_DATA[range];
    if(chart) chart.destroy();
    chart = new Chart(ctx, {
      type:'line',
      data:{
        labels:d.labels,
        datasets:[
          { label:'Clicks', data:d.clicks, borderColor:'#16234A', backgroundColor:'rgba(22,35,74,0.08)', tension:0.35, fill:true, pointRadius:0 },
          { label:'Conversions', data:d.conversions, borderColor:'#4C8C2B', backgroundColor:'rgba(76,140,43,0.12)', tension:0.35, fill:true, pointRadius:0, yAxisID:'y1' },
        ]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'bottom', labels:{ boxWidth:10, font:{ size:11 } } } },
        scales:{
          y:{ grid:{ color:'#EEF1EC' }, ticks:{ font:{ size:11 } } },
          y1:{ position:'right', grid:{ display:false }, ticks:{ font:{ size:11 } } },
          x:{ grid:{ display:false }, ticks:{ font:{ size:11 } } },
        }
      }
    });
  }

  const toggle = document.getElementById('rangeToggle');
  toggle.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if(!btn) return;
    toggle.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    draw(btn.dataset.range);
  });
  draw('7d');
}

/* ---------- dashboard: real data + auto-refresh ---------- */
const TX_TYPE_LABELS = {
  affiliate_commission: 'Affiliate commission',
  referral_bonus: 'Referral bonus',
  activity_reward: 'Activity reward',
  competition_reward: 'Competition reward',
  bonus: 'Bonus',
  admin_adjustment: 'Account adjustment',
  withdrawal: 'Withdrawal',
};
const TX_ICON_IN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 7l3-3a5 5 0 0 0-7-7"/><path d="M14 11a5 5 0 0 0-7-7L4 7a5 5 0 0 0 7 7"/></svg>';
const TX_ICON_OUT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';

let dashboardPollTimer = null;
let lastRefreshAt = null;

function money(n){
  const v = Number(n) || 0;
  return 'KSh ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderWallet(payload){
  const bal = payload.balance || {};
  const set = (id, text) => { const el = document.getElementById(id); if(el) el.textContent = text; };

  set('dashBalance', 'KES ' + Number(bal.available_balance || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  set('withdrawBalanceDisplay', 'KES ' + Number(bal.available_balance || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  set('statTotalEarnings', money(bal.lifetime_earnings));
  set('statTodayEarnings', money(bal.today_earnings));
  set('statAffiliateEarnings', money(bal.affiliate_earnings));
  set('statReferralEarnings', money(bal.referral_earnings));

  const tbody = document.getElementById('txTableBody');
  if(tbody){
    const txs = payload.transactions || [];
    if(txs.length === 0){
      tbody.innerHTML = '<tr><td colspan="4" class="text-faint">No transactions yet — go earn something!</td></tr>';
    } else {
      tbody.innerHTML = txs.map(tx => {
        const amt = Number(tx.amount);
        const isOut = amt < 0;
        const label = TX_TYPE_LABELS[tx.type] || tx.type;
        const desc = tx.description || label;
        return `
          <tr>
            <td><span class="tx-type"><span class="tx-icon ${isOut ? 'out' : 'in'}">${isOut ? TX_ICON_OUT : TX_ICON_IN}</span>${desc}</span></td>
            <td>${label}</td>
            <td class="tx-amt ${isOut ? 'out' : 'in'}">${isOut ? '\u2212' : '+'}${money(Math.abs(amt))}</td>
            <td><span class="tx-status ${tx.status}">${tx.status.charAt(0).toUpperCase() + tx.status.slice(1)}</span></td>
          </tr>`;
      }).join('');
    }
  }
}

function renderReferrals(payload){
  const linkInput = document.getElementById('myReferralLink');
  if(linkInput && payload.referral_link) linkInput.value = payload.referral_link;

  const tbody = document.getElementById('referralsTableBody');
  if(!tbody) return;
  const refs = payload.referrals || [];
  if(refs.length === 0){
    tbody.innerHTML = '<tr><td colspan="3" class="text-faint">No referrals yet — share your link above.</td></tr>';
    return;
  }
  tbody.innerHTML = refs.map(r => `
    <tr>
      <td>${r.username}</td>
      <td><span class="tx-status ${r.status === 'active' ? 'completed' : 'pending'}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></td>
      <td class="text-faint">${new Date(r.joined_at).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' })}</td>
    </tr>`).join('');
}

function updateRefreshLabel(){
  const label = document.getElementById('refreshLabel');
  if(!label || !lastRefreshAt) return;
  const secs = Math.round((Date.now() - lastRefreshAt) / 1000);
  label.textContent = secs < 3 ? 'Live' : `Updated ${secs}s ago`;
}

async function refreshDashboard(){
  const [walletRes, referralsRes] = await Promise.all([
    Api.get('/api/wallet.php'),
    Api.get('/api/referrals.php'),
  ]);
  if(walletRes.data.success) renderWallet(walletRes.data);
  if(referralsRes.data.success) renderReferrals(referralsRes.data);
  lastRefreshAt = Date.now();
  updateRefreshLabel();
}

async function submitWithdrawal(){
  const amountInput = document.getElementById('wAmount');
  const errorBox = document.getElementById('withdrawError');
  const btn = document.getElementById('withdrawBtn');
  errorBox.style.display = 'none';

  const amount = parseFloat(amountInput.value);
  if(!amount || amount <= 0){
    errorBox.textContent = 'Enter a valid amount.';
    errorBox.style.display = 'block';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Requesting…';
  const { data } = await Api.post('/api/withdraw.php', { amount });
  btn.disabled = false;
  btn.textContent = 'Request Withdrawal';

  if(data.success){
    amountInput.value = '';
    showToast(data.email_sent
      ? 'Withdrawal requested — check your email for confirmation.'
      : 'Withdrawal requested — it is now pending review.');
    refreshDashboard();
  } else {
    errorBox.textContent = data.error || 'Could not submit withdrawal request.';
    errorBox.style.display = 'block';
  }
}

async function initDashboard(){
  const balanceEl = document.getElementById('dashBalance');
  if(!balanceEl) return; // not the dashboard page

  const user = await Api.requireAuth();
  if(!user) return; // redirected to login

  const nameEl = document.getElementById('greetName');
  if(nameEl) nameEl.textContent = user.username;
  const avatarEl = document.getElementById('dashAvatar');
  if(avatarEl) avatarEl.style.backgroundImage = `url('https://picsum.photos/seed/mulaearn-${encodeURIComponent(user.username)}/100/100')`;

  await refreshDashboard();

  // Auto-refresh every 20s. Pauses while the tab is hidden so we're not
  // polling a backgrounded tab, and refreshes immediately when it's
  // foregrounded again so the data is never more than 20s stale while
  // actually being looked at.
  dashboardPollTimer = setInterval(() => {
    if(!document.hidden) refreshDashboard();
  }, 20000);
  setInterval(updateRefreshLabel, 1000);

  document.addEventListener('visibilitychange', () => {
    if(!document.hidden) refreshDashboard();
  });
}


const QUIZZES = {
  'General Knowledge': [
    { q:'Who invented the World Wide Web?', options:['Tim Berners-Lee','Alan Turing','Bill Gates','Steve Jobs'], correct:0 },
    { q:'What is the largest ocean on Earth?', options:['Atlantic','Indian','Arctic','Pacific'], correct:3 },
    { q:'How many continents are there?', options:['5','6','7','8'], correct:2 },
  ],
  'Technology': [
    { q:'What does "CPU" stand for?', options:['Central Process Unit','Central Processing Unit','Computer Personal Unit','Central Processor Utility'], correct:1 },
    { q:'Which company developed the Android OS?', options:['Apple','Microsoft','Google','Amazon'], correct:2 },
    { q:'What year was the first iPhone released?', options:['2005','2007','2009','2010'], correct:1 },
  ],
  'Geography': [
    { q:'What is the capital of Kenya?', options:['Mombasa','Kisumu','Nairobi','Nakuru'], correct:2 },
    { q:'Which is the longest river in the world?', options:['Amazon','Nile','Yangtze','Mississippi'], correct:1 },
    { q:'Mount Kilimanjaro is located in which country?', options:['Kenya','Uganda','Tanzania','Ethiopia'], correct:2 },
  ],
};

let quizState = { category:'General Knowledge', index:0, score:0, timer:null, seconds:15 };

function startQuiz(category){
  quizState = { category, index:0, score:0, timer:null, seconds:15 };
  document.querySelectorAll('.trivia-cats button').forEach(b => b.classList.toggle('active', b.dataset.cat === category));
  renderQuestion();
}

function renderQuestion(){
  const panel = document.getElementById('quizPanel');
  const set = QUIZZES[quizState.category];
  if(!panel || !set) return;
  clearInterval(quizState.timer);

  if(quizState.index >= set.length){
    panel.innerHTML = `
      <div class="quiz-result">
        <div style="font-size:13px; color:var(--ink-faint); text-transform:uppercase; letter-spacing:.05em;">Quiz Complete!</div>
        <div class="score">${quizState.score}/${set.length}</div>
        <p style="color:var(--ink-soft); font-size:13.5px; margin-top:6px;">Points earned: +${quizState.score * 10}</p>
        <button class="btn btn-primary" style="margin-top:18px;" onclick="startQuiz('${quizState.category}')">Play Again</button>
      </div>`;
    return;
  }

  const item = set[quizState.index];
  quizState.seconds = 15;
  panel.innerHTML = `
    <div class="quiz-top">
      <span>${quizState.category} · Question ${quizState.index + 1}/${set.length}</span>
      <span class="quiz-timer" id="quizTimer">${quizState.seconds}s</span>
    </div>
    <div class="quiz-question">${item.q}</div>
    <div class="quiz-options" id="quizOptions">
      ${item.options.map((opt,i) => `<button class="quiz-option" data-i="${i}">${opt}</button>`).join('')}
    </div>
  `;

  const optionsEl = document.getElementById('quizOptions');
  optionsEl.addEventListener('click', (e) => {
    const btn = e.target.closest('.quiz-option');
    if(!btn || optionsEl.dataset.locked) return;
    answerQuestion(parseInt(btn.dataset.i, 10));
  });

  quizState.timer = setInterval(() => {
    quizState.seconds--;
    const t = document.getElementById('quizTimer');
    if(t) t.textContent = quizState.seconds + 's';
    if(quizState.seconds <= 0){
      clearInterval(quizState.timer);
      answerQuestion(-1);
    }
  }, 1000);
}

function answerQuestion(selected){
  clearInterval(quizState.timer);
  const set = QUIZZES[quizState.category];
  const item = set[quizState.index];
  const optionsEl = document.getElementById('quizOptions');
  optionsEl.dataset.locked = 'true';
  [...optionsEl.children].forEach((btn,i) => {
    if(i === item.correct) btn.classList.add('correct');
    else if(i === selected) btn.classList.add('wrong');
  });
  if(selected === item.correct) quizState.score++;
  setTimeout(() => { quizState.index++; renderQuestion(); }, 1000);
}

function initTrivia(){
  const catRow = document.getElementById('triviaCats');
  if(!catRow) return;
  catRow.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if(!btn) return;
    startQuiz(btn.dataset.cat);
  });
  startQuiz('General Knowledge');
}

document.addEventListener('DOMContentLoaded', () => {
  initReferral();
  initRegisterForm();
  initLoginForm();
  initCampaignFilters();
  initAffiliateChart();
  initTrivia();
  initDashboard();
  initAffiliatePage();
});
