/* =========================================================
   Courtside Scoreboard
   - Timer / Stopwatch toggle
   - Score, foul, timeout tracking for two teams
   - Warning lights + buzzer in the last 3 seconds (timer mode only)
   - Syncs to Tbl_team / Tbl_buffer through backend.php
   ========================================================= */

const API_URL = "backend.php";
const SYNC_DEBOUNCE_MS = 700;   // avoid hammering the DB while buttons are mashed
const CLOCK_SYNC_MS = 1000;     // how often the running clock writes to the DB
const WARNING_SECONDS = 3;      // last N seconds of TIMER mode trigger lights + sound
const STAT_START = 5;            // fouls and timeouts both start here and count down to 0

/* ---------------- state ---------------- */
const state = {
  mode: "timer",           // "timer" | "stopwatch"
  running: false,
  periodIndex: 0,          // 0 = Q1 .. 3 = Q4, 4+ = OT1, OT2, ...
  timerStartMs: 10 * 60 * 1000, // default 10:00 for TIMER mode
  elapsedMs: 0,            // ms shown right now
  lastTick: null,          // performance.now() at last tick while running
  warningFired: false,     // guard so the buzzer only fires once per crossing
  teams: {
    A: { name: "", photo: "", score: 0, foul: STAT_START, to: STAT_START },
    B: { name: "", photo: "", score: 0, foul: STAT_START, to: STAT_START }
  }
};

const PERIOD_NAMES = ["Q1", "Q2", "Q3", "Q4"];

/* ---------------- element refs ---------------- */
const el = (id) => document.getElementById(id);
const clockDisplay = el("clockDisplay");
const clockFace = el("clockFace");
const lampL = el("lampL");
const lampR = el("lampR");
const startPauseBtn = el("startPauseBtn");
const modeTimerBtn = el("modeTimer");
const modeStopwatchBtn = el("modeStopwatch");
const periodLabel = el("periodLabel");
const overtimeBtn = el("overtimeBtn");
const syncDot = el("syncDot");
const syncLabel = el("syncLabel");
const arena = document.querySelector(".arena");

/* ---------------- audio (WebAudio, no external file needed) ---------------- */
let audioCtx = null;
function getAudioCtx() {
  if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  return audioCtx;
}
function beep(freq = 880, durationMs = 150, type = "square", volume = 0.18) {
  try {
    const ctx = getAudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.frequency.value = freq;
    gain.gain.value = volume;
    osc.connect(gain).connect(ctx.destination);
    osc.start();
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + durationMs / 1000);
    osc.stop(ctx.currentTime + durationMs / 1000);
  } catch (e) { /* audio may be blocked until first user gesture; ignore */ }
}
function warningTick() { beep(1046, 110, "square", 0.16); }   // short high tick each of the last 3 seconds

// Buzzer played once the timer hits 00:00.0 — two short blasts read more
// like a real game buzzer than a single tone.
function finalBuzzer() {
  beep(180, 700, "sawtooth", 0.26);
  setTimeout(() => beep(180, 700, "sawtooth", 0.26), 800);
}

/* ---------------- formatting ---------------- */
function formatClock(ms) {
  if (ms < 0) ms = 0;
  const totalTenths = Math.floor(ms / 100);
  const minutes = Math.floor(totalTenths / 600);
  const seconds = Math.floor((totalTenths % 600) / 10);
  const tenths = totalTenths % 10;
  return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}.${tenths}`;
}
// mm:ss.mmm form for the Clock columns in the DB, matching the seed data's shape
function formatClockForDb(ms) {
  if (ms < 0) ms = 0;
  const totalMs = Math.floor(ms);
  const minutes = Math.floor(totalMs / 60000);
  const seconds = Math.floor((totalMs % 60000) / 1000);
  const millis = totalMs % 1000;
  return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}.${String(millis).padStart(3, "0")}`;
}

function currentDisplayMs() {
  if (state.mode === "timer") {
    return Math.max(state.timerStartMs - state.elapsedMs, 0);
  }
  return state.elapsedMs;
}

/* ---------------- render ---------------- */
function renderClock() {
  const ms = currentDisplayMs();
  clockDisplay.textContent = formatClock(ms);

  const secondsLeft = ms / 1000;
  const inWarningWindow = state.mode === "timer" && state.running && secondsLeft <= WARNING_SECONDS && secondsLeft > 0;

  clockFace.classList.toggle("warning", state.mode === "timer" && secondsLeft <= WARNING_SECONDS && secondsLeft >= 0);
  lampL.classList.toggle("on", inWarningWindow);
  lampR.classList.toggle("on", inWarningWindow);
  arena.classList.toggle("flashing", inWarningWindow);
}

function renderPeriod() {
  const idx = state.periodIndex;
  const inOt = idx >= 4;
  periodLabel.textContent = inOt ? `OT${idx - 3}` : PERIOD_NAMES[idx];
  overtimeBtn.classList.toggle("active", inOt);
  overtimeBtn.textContent = inOt ? `Overtime — OT${idx - 3} active` : "Overtime";
}

function renderTeam(team) {
  const t = state.teams[team];
  el(`score${team}`).textContent = t.score;
  el(`foul${team}`).textContent = t.foul;
  el(`to${team}`).textContent = t.to;
  const nameInput = el(`name${team}`);
  if (document.activeElement !== nameInput) nameInput.value = t.name;

  const img = el(`photo${team}Preview`);
  if (t.photo) {
    img.src = t.photo;
    img.classList.add("has-img");
  } else {
    img.classList.remove("has-img");
  }

  // Foul/timeout counters count down from STAT_START to 0: grey out the
  // "–" button and mark the number red once a team's run out.
  updateStatDepleted(team, "foul", t.foul);
  updateStatDepleted(team, "to", t.to);
}

function updateStatDepleted(team, stat, value) {
  const valueEl = el(`${stat}${team}`);
  const minusBtn = document.querySelector(`.stat-btn.minus[data-team="${team}"][data-stat="${stat}"]`);
  const depleted = value <= 0;
  valueEl.classList.toggle("at-limit", depleted);
  if (minusBtn) minusBtn.disabled = depleted;
}

function renderAll() {
  renderClock();
  renderPeriod();
  renderTeam("A");
  renderTeam("B");
}

// Keeps the warning lamps + red flash going for a couple seconds after the
// buzzer, even though the clock itself has already stopped at 00:00.0.
function flashTimeUp() {
  let count = 0;
  const flashInterval = setInterval(() => {
    const on = count % 2 === 0;
    lampL.classList.toggle("on", on);
    lampR.classList.toggle("on", on);
    arena.classList.toggle("flashing", on);
    count++;
    if (count >= 6) { // ~3 seconds at 500ms per step
      clearInterval(flashInterval);
      lampL.classList.remove("on");
      lampR.classList.remove("on");
      arena.classList.remove("flashing");
    }
  }, 500);
}

/* ---------------- clock engine ---------------- */
let rafId = null;

function tick(now) {
  if (!state.running) return;
  const delta = now - state.lastTick;
  state.lastTick = now;
  state.elapsedMs += delta;

  if (state.mode === "timer" && state.elapsedMs >= state.timerStartMs) {
    state.elapsedMs = state.timerStartMs;
    renderClock();
    stopClock();
    finalBuzzer();
    flashTimeUp();
    queueSync("clock");
    return;
  }

  // fire one tick of the warning beep each time we cross into a new whole second
  // inside the warning window (3, 2, 1)
  if (state.mode === "timer") {
    const secondsLeft = Math.ceil((state.timerStartMs - state.elapsedMs) / 1000);
    if (secondsLeft <= WARNING_SECONDS && secondsLeft >= 1) {
      if (state.lastWarningSecond !== secondsLeft) {
        state.lastWarningSecond = secondsLeft;
        warningTick();
      }
    }
  }

  renderClock();
  rafId = requestAnimationFrame(tick);
}

function startClock() {
  if (state.running) return;
  state.running = true;
  state.lastTick = performance.now();
  state.lastWarningSecond = null;
  startPauseBtn.textContent = "PAUSE";
  startPauseBtn.classList.add("running");
  rafId = requestAnimationFrame(tick);
  startClockSyncInterval();
}

function stopClock() {
  state.running = false;
  startPauseBtn.textContent = "START";
  startPauseBtn.classList.remove("running");
  if (rafId) cancelAnimationFrame(rafId);
  stopClockSyncInterval();
  renderClock();
  queueSync("clock");
}

function toggleStartPause() {
  // resume/suspend audio context on first user gesture (browser autoplay policy)
  if (audioCtx && audioCtx.state === "suspended") audioCtx.resume();
  if (state.running) stopClock(); else startClock();
}

function resetClock() {
  stopClock();
  state.elapsedMs = 0;
  state.lastWarningSecond = null;
  renderClock();
  queueSync("clock");
}

let clockSyncTimer = null;
function startClockSyncInterval() {
  stopClockSyncInterval();
  clockSyncTimer = setInterval(() => queueSync("clock"), CLOCK_SYNC_MS);
}
function stopClockSyncInterval() {
  if (clockSyncTimer) clearInterval(clockSyncTimer);
  clockSyncTimer = null;
}

/* ---------------- mode toggle ---------------- */
function setMode(mode) {
  if (state.mode === mode) return;
  stopClock();
  state.mode = mode;
  state.elapsedMs = 0;
  modeTimerBtn.classList.toggle("active", mode === "timer");
  modeStopwatchBtn.classList.toggle("active", mode === "stopwatch");
  renderClock();
  queueSync("clock");
}

/* ---------------- period controls ---------------- */
function changePeriod(delta) {
  const next = state.periodIndex + delta;
  if (next < 0) return;
  state.periodIndex = next;
  renderPeriod();
  // Saving the period edge also snapshots the current score/clock into Tbl_team
  syncNow("period");
}

// Jumps straight into overtime (OT1 the first time, OT2 the next, etc.)
// and resets the clock so the new period starts fresh.
function startOvertime() {
  stopClock();
  state.periodIndex = state.periodIndex < 4 ? 4 : state.periodIndex + 1;
  state.elapsedMs = 0;
  state.lastWarningSecond = null;
  renderPeriod();
  renderClock();
  // Written immediately (no debounce) so every click shows up in Tbl_team's
  // OT column in phpMyAdmin right away — OT1, then OT2, then OT3...
  syncNow("period");
}

/* ---------------- score / foul / timeout ---------------- */
function addPoints(team, pts) {
  state.teams[team].score += pts;
  renderTeam(team);
  queueSync("score", team);
}

function adjustStat(team, stat, dir) {
  const t = state.teams[team];
  if (stat === "foul") {
    t.foul = Math.min(STAT_START, Math.max(0, t.foul + dir));
  } else if (stat === "to") {
    t.to = Math.min(STAT_START, Math.max(0, t.to + dir));
  }
  renderTeam(team);
  queueSync("stat", team);
}

function setTeamName(team, name) {
  state.teams[team].name = name;
  queueSync("name", team);
}

function setTeamPhoto(team, dataUrl) {
  state.teams[team].photo = dataUrl;
  renderTeam(team);
  // Photos are kept client-side (localStorage) since the provided schema has
  // no image column. See README if you want to persist them server-side too.
  persistLocal();
}

/* ---------------- set time modal ---------------- */
const setModal = el("setModal");
function openSetModal() {
  const ms = state.mode === "timer" ? state.timerStartMs : state.elapsedMs;
  el("setMinutes").value = Math.floor(ms / 60000);
  el("setSeconds").value = Math.floor((ms % 60000) / 1000);
  setModal.classList.remove("hidden");
}
function closeSetModal() { setModal.classList.add("hidden"); }
function applySetModal() {
  const mins = Math.max(0, Math.min(99, parseInt(el("setMinutes").value, 10) || 0));
  const secs = Math.max(0, Math.min(59, parseInt(el("setSeconds").value, 10) || 0));
  const ms = (mins * 60 + secs) * 1000;

  stopClock();
  if (state.mode === "timer") {
    state.timerStartMs = ms;
    state.elapsedMs = 0;
  } else {
    state.elapsedMs = ms;
  }
  state.lastWarningSecond = null;
  renderClock();
  queueSync("clock");
  closeSetModal();
}

/* ---------------- reset match ---------------- */
function resetMatch() {
  if (!confirm("Reset the whole match? This clears scores, fouls, timeouts and the clock.")) return;
  stopClock();
  state.periodIndex = 0;
  state.elapsedMs = 0;
  ["A", "B"].forEach((team) => {
    state.teams[team].score = 0;
    state.teams[team].foul = STAT_START;
    state.teams[team].to = STAT_START;
  });
  renderAll();
  queueSync("full");
}

/* =========================================================
   DB SYNC
   Tbl_team   -> id 1 = Team A, id 2 = Team B (Team, Points, Clock, quarter, OT)
   Tbl_buffer -> id 1 = Team A, id 2 = Team B (Team, Points, Foul, T/O, Clock)
   Every write recomputes both rows for the affected team(s) and posts a
   single JSON payload per team to backend.php, which upserts both tables.
   ========================================================= */

let syncTimeout = null;
let pendingReasons = new Set();

function queueSync(reason, team) {
  pendingReasons.add(reason);
  if (team) pendingReasons.add(`team:${team}`);
  if (syncTimeout) clearTimeout(syncTimeout);
  setSyncStatus("saving");
  syncTimeout = setTimeout(runSync, SYNC_DEBOUNCE_MS);
}

// Writes to the database right away instead of waiting out the debounce —
// used for period/overtime changes, since those are infrequent clicks and
// it should be obvious each one landed in Tbl_team immediately.
function syncNow(reason) {
  pendingReasons.add(reason);
  if (syncTimeout) clearTimeout(syncTimeout);
  setSyncStatus("saving");
  runSync();
}

function setSyncStatus(status) {
  syncDot.classList.remove("saving", "error");
  if (status === "saving") { syncDot.classList.add("saving"); syncLabel.textContent = "saving…"; }
  else if (status === "error") { syncDot.classList.add("error"); syncLabel.textContent = "offline"; }
  else { syncLabel.textContent = "saved"; }
}

async function runSync() {
  const reasons = pendingReasons;
  pendingReasons = new Set();

  const quarter = state.periodIndex < 4 ? state.periodIndex + 1 : 4;
  const ot = state.periodIndex < 4 ? 0 : state.periodIndex - 3;
  const clockStr = formatClockForDb(currentDisplayMs());

  const payload = {
    quarter,
    ot,
    clock: clockStr,
    teams: {
      1: { // Team A -> row id 1
        name: state.teams.A.name || "Team A",
        points: state.teams.A.score,
        foul: state.teams.A.foul,
        timeouts: state.teams.A.to
      },
      2: { // Team B -> row id 2
        name: state.teams.B.name || "Team B",
        points: state.teams.B.score,
        foul: state.teams.B.foul,
        timeouts: state.teams.B.to
      }
    }
  };

  try {
    const res = await fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    if (!res.ok) throw new Error("bad response");
    setSyncStatus("ok");
  } catch (err) {
    setSyncStatus("error");
  }
  persistLocal();
}

/* ---------------- local persistence (photos + last state, survives refresh) ---------------- */
function persistLocal() {
  try {
    localStorage.setItem("courtside_state", JSON.stringify(state));
  } catch (e) { /* storage might be full/unavailable; scoreboard still works */ }
}
function restoreLocal() {
  try {
    const raw = localStorage.getItem("courtside_state");
    if (!raw) return;
    const saved = JSON.parse(raw);
    Object.assign(state, saved, { running: false, lastTick: null });
  } catch (e) { /* ignore corrupt/unavailable storage */ }
}

/* ---------------- load initial state from the database ---------------- */
async function restoreFromDb() {
  try {
    const res = await fetch(API_URL, { method: "GET" });
    const data = await res.json();
    if (!data.ok) return;

    const byId = {};
    data.buffer.forEach((row) => { byId[row.id] = row; });
    const teamRows = {};
    data.team.forEach((row) => { teamRows[row.id] = row; });

    const map = { 1: "A", 2: "B" };
    Object.entries(map).forEach(([id, team]) => {
      const buf = byId[id];
      const trow = teamRows[id];
      if (buf) {
        if (buf.Team) state.teams[team].name = buf.Team;
        state.teams[team].score = Number(buf.Points) || 0;
        state.teams[team].foul = Number(buf.Foul) || 0;
        state.teams[team].to = Number(buf['T/O']) ?? STAT_START;
      }
      if (trow) {
        state.periodIndex = Math.max(0, (Number(trow.quarter) || 1) - 1) + (Number(trow.OT) || 0);
      }
    });
    renderAll();
    setSyncStatus("ok");
  } catch (e) {
    // No PHP/DB available (e.g. opening index.html directly as a file) — the
    // scoreboard still works fully offline using localStorage only.
    setSyncStatus("error");
  }
}

/* ---------------- wire up events ---------------- */
function init() {
  restoreLocal();
  restoreFromDb();

  modeTimerBtn.addEventListener("click", () => setMode("timer"));
  modeStopwatchBtn.addEventListener("click", () => setMode("stopwatch"));
  startPauseBtn.addEventListener("click", toggleStartPause);
  el("resetBtn").addEventListener("click", resetClock);
  el("setBtn").addEventListener("click", openSetModal);
  el("cancelSet").addEventListener("click", closeSetModal);
  el("confirmSet").addEventListener("click", applySetModal);
  el("prevPeriod").addEventListener("click", () => changePeriod(-1));
  el("nextPeriod").addEventListener("click", () => changePeriod(1));
  el("overtimeBtn").addEventListener("click", startOvertime);
  el("resetAllBtn").addEventListener("click", resetMatch);

  document.querySelectorAll(".pts-btn").forEach((btn) => {
    btn.addEventListener("click", () => addPoints(btn.dataset.team, parseInt(btn.dataset.pts, 10)));
  });
  document.querySelectorAll(".stat-btn").forEach((btn) => {
    btn.addEventListener("click", () => adjustStat(btn.dataset.team, btn.dataset.stat, parseInt(btn.dataset.dir, 10)));
  });

  ["A", "B"].forEach((team) => {
    el(`name${team}`).addEventListener("input", (e) => setTeamName(team, e.target.value));
    el(`photo${team}`).addEventListener("change", (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => setTeamPhoto(team, reader.result);
      reader.readAsDataURL(file);
    });
  });

  setModal.addEventListener("click", (e) => { if (e.target === setModal) closeSetModal(); });

  renderAll();
}

document.addEventListener("DOMContentLoaded", init);