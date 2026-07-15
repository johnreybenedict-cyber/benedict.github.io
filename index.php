<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Scoreboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Bebas+Neue&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-0:#05070a;
    --bg-1:#0c1118;
    --panel:#10161f;
    --line:#1e2733;
    --amber:#ffb020;
    --amber-dim:#7a5813;
    --team-a:#e2483a;
    --team-b:#2f8fe0;
    --text-hi:#f4f6f8;
    --text-lo:#7c8896;
    --live:#e2483a;
  }

  *{box-sizing:border-box; margin:0; padding:0;}

  html,body{
    height:100%;
    background:var(--bg-0);
    color:var(--text-hi);
    font-family:'Inter',sans-serif;
    overflow:hidden;
  }

  body{
    background:
      radial-gradient(ellipse at 50% -10%, #131b26 0%, var(--bg-0) 60%),
      repeating-linear-gradient(0deg, rgba(255,255,255,0.015) 0px, rgba(255,255,255,0.015) 1px, transparent 1px, transparent 3px);
  }

  .board{
    height:100vh;
    display:grid;
    grid-template-rows:auto 1fr auto;
    padding:clamp(12px,2vw,28px);
    gap:clamp(10px,1.6vw,18px);
  }

  /* ---------- TOP BAR ---------- */
  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 22px;
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:10px;
  }
  .brand{
    font-family:'Bebas Neue',sans-serif;
    letter-spacing:.12em;
    font-size:clamp(14px,1.6vw,20px);
    color:var(--text-lo);
  }
  .status{
    display:flex; align-items:center; gap:8px;
    font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:var(--text-lo);
  }
  .status .dot{
    width:9px; height:9px; border-radius:50%;
    background:var(--live);
    box-shadow:0 0 8px var(--live);
    animation:pulse 1.4s ease-in-out infinite;
  }
  .status.offline .dot{
    background:#4a4a4a; box-shadow:none; animation:none;
  }
  @keyframes pulse{
    0%,100%{opacity:1;} 50%{opacity:.35;}
  }

  /* ---------- MAIN ---------- */
  .main{
    display:grid;
    grid-template-columns:1fr auto 1fr;
    align-items:stretch;
    gap:clamp(10px,1.6vw,18px);
  }

  .team{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:14px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    padding:clamp(16px,2.4vw,34px);
    position:relative;
    overflow:hidden;
  }
  .team::before{
    content:"";
    position:absolute; top:0; left:0; right:0; height:4px;
  }
  .team.a::before{ background:var(--team-a); }
  .team.b::before{ background:var(--team-b); }

  .team-name{
    font-family:'Bebas Neue',sans-serif;
    letter-spacing:.04em;
    font-size:clamp(24px,3.4vw,44px);
    line-height:1.05;
    color:var(--text-hi);
    text-transform:uppercase;
    word-break:break-word;
  }
  .team.a .team-name{ border-left:6px solid var(--team-a); padding-left:14px; }
  .team.b .team-name{ border-left:6px solid var(--team-b); padding-left:14px; }

  .points{
    font-family:'Orbitron',sans-serif;
    font-weight:900;
    font-size:clamp(70px,13vw,190px);
    line-height:1;
    text-align:center;
    color:var(--text-hi);
    text-shadow:0 0 30px rgba(244,246,248,0.08);
    font-variant-numeric:tabular-nums;
  }

  .team-meta{
    display:flex;
    justify-content:space-between;
    gap:12px;
  }
  .meta-block{
    flex:1;
    background:var(--bg-1);
    border:1px solid var(--line);
    border-radius:10px;
    padding:10px 12px;
    text-align:center;
  }
  .meta-label{
    font-size:11px;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:var(--text-lo);
    margin-bottom:6px;
  }
  .meta-value{
    font-family:'Orbitron',sans-serif;
    font-weight:700;
    font-size:clamp(18px,2.2vw,28px);
    color:var(--text-hi);
  }
  .timeout-dots{
    display:flex;
    justify-content:center;
    gap:6px;
    margin-top:2px;
  }
  .to-dot{
    width:10px; height:10px; border-radius:50%;
    background:var(--bg-1);
    border:2px solid var(--text-lo);
  }
  .team.a .to-dot.filled{ background:var(--team-a); border-color:var(--team-a); }
  .team.b .to-dot.filled{ background:var(--team-b); border-color:var(--team-b); }

  /* ---------- CENTER CLOCK MODULE ---------- */
  .center{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:14px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:clamp(10px,1.4vw,18px);
    padding:clamp(20px,2.4vw,36px);
    min-width:clamp(220px,22vw,340px);
  }
  .period-badge{
    font-family:'Bebas Neue',sans-serif;
    letter-spacing:.1em;
    font-size:clamp(16px,2vw,22px);
    color:var(--amber);
    border:1px solid var(--amber-dim);
    background:rgba(255,176,32,0.08);
    padding:6px 22px;
    border-radius:999px;
    text-transform:uppercase;
  }
  .clock{
    font-family:'Orbitron',sans-serif;
    font-weight:700;
    font-size:clamp(56px,9vw,120px);
    letter-spacing:.02em;
    color:var(--amber);
    text-shadow:0 0 26px rgba(255,176,32,0.35);
    font-variant-numeric:tabular-nums;
  }
  .clock-label{
    font-size:11px;
    letter-spacing:.2em;
    text-transform:uppercase;
    color:var(--text-lo);
  }

  /* ---------- BOTTOM TICKER ---------- */
  .ticker{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    padding:8px;
    font-size:11px;
    letter-spacing:.15em;
    text-transform:uppercase;
    color:var(--text-lo);
  }
  .ticker b{ color:var(--text-hi); }

  @media (max-width:820px){
    .main{ grid-template-columns:1fr; grid-template-rows:auto auto auto; }
    .center{ order:-1; }
  }
</style>
</head>
<body>

<div class="board">

  <div class="topbar">
    <div class="brand">LIVE SCOREBOARD</div>
    <div class="status offline" id="status"><span class="dot"></span><span id="statusText">Connecting…</span></div>
  </div>

  <div class="main">
    <div class="team a" id="teamA">
      <div class="team-name" id="nameA">Team A</div>
      <div class="points" id="pointsA">0</div>
      <div class="team-meta">
        <div class="meta-block">
          <div class="meta-label">Fouls</div>
          <div class="meta-value" id="foulsA">0</div>
        </div>
        <div class="meta-block">
          <div class="meta-label">Timeouts</div>
          <div class="timeout-dots" id="toA"></div>
        </div>
      </div>
    </div>

    <div class="center">
      <div class="period-badge" id="period">Q1</div>
      <div class="clock" id="clock">00:00</div>
      <div class="clock-label">Game Clock</div>
    </div>

    <div class="team b" id="teamB">
      <div class="team-name" id="nameB">Team B</div>
      <div class="points" id="pointsB">0</div>
      <div class="team-meta">
        <div class="meta-block">
          <div class="meta-label">Fouls</div>
          <div class="meta-value" id="foulsB">0</div>
        </div>
        <div class="meta-block">
          <div class="meta-label">Timeouts</div>
          <div class="timeout-dots" id="toB"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ticker">Auto-refreshing every second · <b id="lastUpdate">—</b></div>

</div>

<script>
  // Max timeouts to render as dots (adjust to your league's rules)
  const MAX_TIMEOUTS = 5;
  const API_URL = "api.php";
  const POLL_MS = 1000;

  let prevScores = { a: null, b: null };

  function renderDots(container, count, max){
    container.innerHTML = "";
    for(let i=0;i<max;i++){
      const d = document.createElement("div");
      d.className = "to-dot" + (i < count ? " filled" : "");
      container.appendChild(d);
    }
  }

  function flash(el){
    el.style.transition = "none";
    el.style.color = "#ffffff";
    el.style.textShadow = "0 0 40px rgba(255,255,255,0.9)";
    requestAnimationFrame(()=>{
      el.style.transition = "color .6s ease, text-shadow .6s ease";
      el.style.color = "";
      el.style.textShadow = "";
    });
  }

  function setStatus(online, text){
    const s = document.getElementById("status");
    s.classList.toggle("offline", !online);
    document.getElementById("statusText").textContent = text;
  }

  async function refresh(){
    try{
      const res = await fetch(API_URL, { cache: "no-store" });
      if(!res.ok) throw new Error("HTTP " + res.status);
      const data = await res.json();

      const teams = data.teams || [];
      const t1 = teams[0] || { name:"Team A", points:0, fouls:0, timeouts:0 };
      const t2 = teams[1] || { name:"Team B", points:0, fouls:0, timeouts:0 };

      document.getElementById("nameA").textContent = t1.name || "Team A";
      document.getElementById("nameB").textContent = t2.name || "Team B";

      const pointsAEl = document.getElementById("pointsA");
      const pointsBEl = document.getElementById("pointsB");

      if(prevScores.a !== null && t1.points !== prevScores.a) flash(pointsAEl);
      if(prevScores.b !== null && t2.points !== prevScores.b) flash(pointsBEl);
      prevScores = { a: t1.points, b: t2.points };

      pointsAEl.textContent = t1.points ?? 0;
      pointsBEl.textContent = t2.points ?? 0;

      document.getElementById("foulsA").textContent = t1.fouls ?? 0;
      document.getElementById("foulsB").textContent = t2.fouls ?? 0;

      renderDots(document.getElementById("toA"), t1.timeouts ?? 0, MAX_TIMEOUTS);
      renderDots(document.getElementById("toB"), t2.timeouts ?? 0, MAX_TIMEOUTS);

      document.getElementById("clock").textContent = data.clock || "00:00";

      const ot = data.OT || 0;
      const q = data.quarter || 1;
      document.getElementById("period").textContent = ot > 0 ? ("OT" + (ot > 1 ? ot : "")) : ("Q" + q);

      setStatus(true, "Live");
      document.getElementById("lastUpdate").textContent = new Date().toLocaleTimeString();
    }catch(err){
      setStatus(false, "Reconnecting…");
      console.error("Scoreboard fetch failed:", err);
    }
  }

  refresh();
  setInterval(refresh, POLL_MS);
</script>

</body>
</html>