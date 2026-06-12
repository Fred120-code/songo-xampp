let API = "http://localhost/songo/api.php",
  gameId,
  myToken,
  myPlayer,
  animating = false,
  pollTimer = null,
  lastLogCount = 0,
  finished = false;
const Q = (s) => document.getElementById(s);

function apiUrl(a, p = "") {
  return `${API}?action=${a}${p}`;
}

//timer
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

//fonction de l'animationde la distribution
async function animateMove(state, distributionPath) {
  animating = true;
  const tempBoard = JSON.parse(JSON.stringify(state.beforeBoard));
  const ownRow = state.myPlayer === 1 ? 1 : 0;
  const playedCol = state.playedCol; //il manque encore playedCol
  tempBoard[ownRow][playedCol] = 0;

  for (const [r, c] of distributionPath) {
    tempBoard[r][c]++;

    renderBoard({
      ...state,
      board: tempBoard,
      highlight: null,
    });

    await sleep(400);
  }
  animating = false;
}

//pemet de creer une partie
async function createGame() {
  API = Q("api-url").value.trim();
  lobbyErr("");
  try {
    const r = await fetch(apiUrl("create"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
    });
    const d = await r.json();
    if (!r.ok) {
      lobbyErr(d.error || "Erreur");
      return;
    }
    gameId = d.gameId;
    myToken = d.token;
    myPlayer = d.playerNum;
    Q("game-code").textContent = d.code;
    Q("code-block").style.display = "block";
    startPoll();
  } catch {
    lobbyErr(
      "Impossible de contacter le serveur PHP. Vérifiez que XAMPP est lancé.",
    );
  }
}

//permet de rejoindre une partie grace à son code
async function joinGame() {
  API = Q("api-url").value.trim();
  const code = Q("join-code").value.trim().toUpperCase();
  if (!code) {
    lobbyErr("Entrez un code.");
    return;
  }
  lobbyErr("");
  try {
    const r = await fetch(apiUrl("join"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ code }),
    });
    const d = await r.json();
    if (!r.ok) {
      lobbyErr(d.error || "Erreur");
      return;
    }
    gameId = d.gameId;
    myToken = d.token;
    myPlayer = d.playerNum;
    showGame();
    startPoll();
  } catch {
    lobbyErr("Impossible de contacter le serveur PHP.");
  }
}

//pemet de copier le code generé
function copyCode() {
  const c = Q("game-code").textContent;
  navigator.clipboard.writeText(c).then(() => alert("Code copié : " + c));
}

function lobbyErr(m) {
  const e = Q("lobby-err");
  e.textContent = m;
  e.style.display = m ? "block" : "none";
}

function showGame() {
  Q("lobby").style.display = "none";
  Q("game").style.display = "block";
  const id = myPlayer === 1 ? "info-sud" : "info-nord";
  const n = Q(id).querySelector(".pname");
  if (!n.textContent.includes("(vous)")) n.textContent += " (vous)";
}

function backToLobby() {
  stopPoll();
  gameId = myToken = myPlayer = null;
  lastLogCount = 0;
  finished = false;
  Q("end-screen").style.display = "none";
  Q("game").style.display = "none";
  Q("lobby").style.display = "block";
  Q("code-block").style.display = "none";
  Q("join-code").value = "";
  Q("log-list").innerHTML = "";
  Q("info-nord").querySelector(".pname").textContent = "Joueur Nord";
  Q("info-sud").querySelector(".pname").textContent = "Joueur Sud";
}

function startPoll() {
  if (pollTimer) clearInterval(pollTimer);
  poll();
  pollTimer = setInterval(poll, 2000);
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

async function poll() {
  if (animating) return;
  if (!gameId || !myToken) return;
  try {
    const r = await fetch(
      apiUrl("state", `&gameId=${gameId}&token=${myToken}`),
    );
    if (!r.ok) {
      setDot(false);
      return;
    }
    setDot(true);
    handle(await r.json());
  } catch {
    setDot(false);
  }
}

function setDot(on) {
  Q("dot").classList.toggle("off", !on);
}

function handle(s) {
  if (s.status === "waiting") return;
  if (Q("lobby").style.display !== "none") showGame();
  renderBoard(s);
  renderScores(s);
  renderLog(s.log);
  renderStatus(s);
  if (s.status === "finished" && !finished) {
    finished = true;
    showEnd(s.result, s.scores);
    stopPoll();
  }
}

function renderBoard(s) {
  const hl = s.highlight || { last: null, captured: [] };
  const myOwn = s.myPlayer === 1 ? 1 : 0;
  for (let ri = 0; ri < 2; ri++) {
    const el = Q(ri === 0 ? "row-nord" : "row-sud");
    el.innerHTML = "";
    const mine = ri === myOwn;
    for (let ci = 0; ci < 7; ci++) {
      const dn = ri === 0 ? ci + 1 : 7 - ci,
        cell = document.createElement("div");
      cell.className = "cell";
      const isL = hl.last && hl.last[0] === ri && hl.last[1] === ci;
      const isC =
        hl.captured && hl.captured.some(([r, c]) => r === ri && c === ci);
      if (isL && !isC) cell.classList.add("ldrop");
      if (isC) cell.classList.add("capt");
      if (mine && (s.selectableCells || []).includes(ci))
        cell.classList.add("sel");
      const nm = document.createElement("span");
      nm.className = "cnum";
      nm.textContent = dn;
      cell.appendChild(nm);
      const seeds = s.board[ri][ci];
      if (seeds <= 15) {
        const v = document.createElement("div");
        v.className = "seeds-vis";
        for (let k = 0; k < seeds; k++) {
          const d = document.createElement("div");
          d.className = "seed";
          v.appendChild(d);
        }
        cell.appendChild(v);
      } else {
        const cnt = document.createElement("span");
        cnt.className = "scnt";
        cnt.textContent = seeds;
        cell.appendChild(cnt);
      }
      if (mine && (s.selectableCells || []).includes(ci)) {
        const cc = ci;
        cell.onclick = () => sendMove(cc);
      }
      el.appendChild(cell);
    }
  }
}

function renderScores(s) {
  Q("sc-nord").textContent = s.scores[0];
  Q("sc-sud").textContent = s.scores[1];
  const nord = s.currentPlayer === 2 && s.status === "playing",
    sud = s.currentPlayer === 1 && s.status === "playing";
  Q("info-nord").classList.toggle("active", nord);
  Q("info-sud").classList.toggle("active", sud);
  Q("turn-nord").textContent = nord
    ? s.myPlayer === 2
      ? "À votre tour"
      : "À son tour"
    : "\u00a0";
  Q("turn-sud").textContent = sud
    ? s.myPlayer === 1
      ? "À votre tour"
      : "À son tour"
    : "\u00a0";
}

function renderStatus(s) {
  const m = Q("status-msg");
  if (s.status !== "playing") return;
  const mine = s.currentPlayer === s.myPlayer;
  if (mine) {
    m.textContent = "C'est votre tour — choisissez une case.";
    m.className = "myturn";
  } else {
    m.textContent = `En attente du coup de ${s.currentPlayer === 1 ? "Joueur Sud" : "Joueur Nord"}…`;
    m.className = "waiting";
  }
}

function renderLog(log) {
  if (!log || log.length === lastLogCount) return;
  const list = Q("log-list");
  for (let i = lastLogCount; i < log.length; i++) {
    const li = document.createElement("li");
    li.textContent = log[i];
    list.appendChild(li);
  }
  lastLogCount = log.length;
  list.scrollTop = list.scrollHeight;
}

async function sendMove(col) {
  if (animating) return; //on ne joue pas pendant l'animation
  if (!gameId || !myToken) return;
  try {
    const r = await fetch(apiUrl("move"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ gameId, token: myToken, col }),
    });

    const d = await r.json();
    console.log(d);

    if (!r.ok) {
      const m = Q("status-msg");
      m.textContent = d.error || "Coup invalide";
      m.className = "warn";
      return;
    }

    if (d.distributionPath) {
      await animateMove(d, d.distributionPath);
      // const currentState = await fetch(
      //   apiUrl("state", `&gameId=${gameId}&token=${myToken}`),
      // ).then((r) => r.json());

      // await animateMove(currentState, d.distributionPath);
    }

    handle(d);
  } catch {
    setDot(false);
  }
}

function showEnd(result, scores) {
  Q("end-screen").style.display = "block";
  Q("es-nord").textContent = scores[0];
  Q("es-sud").textContent = scores[1];
  let title, sub;
  if (result.winner === 0) {
    title = "Partie nulle";
    sub = "Aucun joueur n'a atteint 40 graines.";
  } else {
    const who =
      result.winner === myPlayer
        ? "Vous gagnez !"
        : (result.winner === 1 ? "Joueur Sud" : "Joueur Nord") + " gagne";
    title = who;
    sub =
      {
        score: "A atteint 40 graines.",
        low: "Moins de 10 graines.",
        solidarity: "Solidarité impossible.",
      }[result.reason] || "";
  }
  Q("end-title").textContent = title;
  Q("end-sub").textContent = sub;
}
