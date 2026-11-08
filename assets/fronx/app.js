/* ============================================================
   SUZUKI FRONX SALES SKILL TEST - EXAM & ANTI-CHEAT ENGINE (app.js)
   Features:
   - Bilingual (EN + اردو) display with live language toggle
   - Seeded question & option shuffle per Employee ID
   - Instant localStorage auto-save + crash recovery
   - 7-Layer Anti-Cheating & AI/ChatGPT Forensics Engine:
     1) Question copy/cut/right-click block + counter
     2) Open-ended paste block + mobile clipboard burst detector
     3) Keystroke cadence, backspace count & WPM human verification
     4) AI/ChatGPT linguistic & markdown fingerprint detector
     5) Tab-switch + Split-screen blur away-duration timer (seconds)
     6) Fullscreen exit & PrintScreen/DevTools shortcut trap
     7) Rapid MCQ speed-click anomaly tracker
   ============================================================ */
(function () {
  const CFG = window.APP_CONFIG || {};
  const AC = CFG.ANTI_CHEAT || {};
  const BANK = window.QUESTION_BANK || { mcq: [], open: [], sections: {} };

  const STORAGE_ACTIVE = "FRONX_ACTIVE_SESSION_V1";
  const STORAGE_RESULTS = "FRONX_RESULTS_DB_V1";

  let state = null;
  let timerInterval = null;
  let lastPayloadForSync = null;
  let awayStartMs = null;
  let qViewStartMs = Date.now();
  let lastKeyTimeMs = 0;
  let toastTimer = null;

  /* ---------- Utilities ---------- */
  function $(id) { return document.getElementById(id); }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function showSecToast(msg) {
    const el = $("secToast");
    if (!el) return;
    el.textContent = msg;
    el.style.display = "block";
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.style.display = "none"; }, 3600);
  }

  function hashSeed(str) {
    let h = 2166136261 >>> 0;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return h >>> 0;
  }

  function mulberry32(a) {
    return function () {
      let t = (a += 0x6d2b79f5);
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function seededShuffle(arr, rng) {
    const copy = arr.slice();
    for (let i = copy.length - 1; i > 0; i--) {
      const j = Math.floor(rng() * (i + 1));
      [copy[i], copy[j]] = [copy[j], copy[i]];
    }
    return copy;
  }

  function getLocalResults() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_RESULTS) || "[]");
    } catch (e) {
      return [];
    }
  }

  function saveLocalResult(record) {
    const list = getLocalResults();
    const idx = list.findIndex((r) => r.submissionId === record.submissionId);
    if (idx >= 0) list[idx] = record;
    else list.unshift(record);
    localStorage.setItem(STORAGE_RESULTS, JSON.stringify(list));
  }

  function isExamActive() {
    return !!(state && state.started && !state.submitted);
  }

  function currentQuestionId() {
    if (!state || !state.items || !state.items[state.currentIndex]) return "—";
    return state.items[state.currentIndex].id;
  }

  function ensureTelemetryObj() {
    if (!state.telemetry) {
      state.telemetry = {
        copyAttempts: 0,
        pasteAttempts: 0,
        burstInserts: 0,
        focusLosses: 0,
        awayTotalSec: 0,
        longestAwaySec: 0,
        fullscreenExits: 0,
        suspiciousShortcuts: 0,
        fastMcqCount: 0,
        qAwaySec: {},
        openStats: {},
        violationLog: []
      };
    }
    return state.telemetry;
  }

  function logViolation(eventLabel) {
    if (!isExamActive()) return;
    const tel = ensureTelemetryObj();
    if (tel.violationLog.length < 45) {
      tel.violationLog.push({
        time: new Date().toLocaleTimeString(),
        qId: currentQuestionId(),
        event: eventLabel
      });
    }
    persistSession(true);
  }

  /* ---------- Initialization & Proctoring Listeners ---------- */
  function initPage() {
    if (CFG.TEST_TITLE) $("hdrTitle").textContent = CFG.TEST_TITLE;
    if (CFG.DEALERSHIP_NAME) {
      $("hdrDealer").textContent =
        CFG.DEALERSHIP_NAME + (CFG.DEALERSHIP_NAME_UR ? "  •  " + CFG.DEALERSHIP_NAME_UR : "");
    }

    const branchSel = $("inpBranch");
    const branches = CFG.BRANCHES && CFG.BRANCHES.length ? CFG.BRANCHES : ["Main Showroom"];
    branchSel.innerHTML = branches
      .map((b) => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`)
      .join("");

    const totalQ = BANK.mcq.length + BANK.open.length;
    $("instTotalQ").textContent = totalQ;
    $("instSubQ").textContent = `${BANK.mcq.length} MCQs + ${BANK.open.length} Pitch Qs`;
    $("instDuration").textContent = CFG.TEST_DURATION_MIN || 50;

    setLangMode(CFG.DEFAULT_LANG_MODE || "both");

    // Check unfinished session
    try {
      const raw = localStorage.getItem(STORAGE_ACTIVE);
      if (raw) {
        const saved = JSON.parse(raw);
        if (saved && saved.candidate && !saved.submitted) {
          $("resumeBanner").classList.remove("hidden");
          const answeredCount = Object.keys(saved.mcqAnswers || {}).length +
            Object.values(saved.openAnswers || {}).filter((v) => (v || "").trim().length > 0).length;
          $("resumeInfo").textContent =
            `${saved.candidate.name} (${saved.candidate.empId}) — ${answeredCount}/${saved.items.length} answered • Remaining time: ${formatSec(saved.remainingSec)}`;
        }
      }
    } catch (e) {}

    setupAntiCheatListeners();
  }

  function setupAntiCheatListeners() {
    // 1. Tab switch & Split-screen Window Blur Away-Time Tracking
    function handleLeaveScreen(reason) {
      if (!isExamActive()) return;
      if (awayStartMs === null) {
        awayStartMs = Date.now();
        if (reason === "hidden") {
          state.tabSwitches = (state.tabSwitches || 0) + 1;
          logViolation("Switched Tab / Minimized App");
          showTabWarning();
        } else {
          const tel = ensureTelemetryObj();
          tel.focusLosses = (tel.focusLosses || 0) + 1;
        }
        persistSession(true);
      }
    }

    function handleReturnScreen() {
      if (!isExamActive() || awayStartMs === null) return;
      const awaySec = Math.round((Date.now() - awayStartMs) / 1000);
      awayStartMs = null;
      if (awaySec >= 2 && AC.TRACK_AWAY_SECONDS !== false) {
        const tel = ensureTelemetryObj();
        tel.awayTotalSec = (tel.awayTotalSec || 0) + awaySec;
        if (awaySec > (tel.longestAwaySec || 0)) tel.longestAwaySec = awaySec;
        const qId = currentQuestionId();
        tel.qAwaySec[qId] = (tel.qAwaySec[qId] || 0) + awaySec;
        if (awaySec >= 4) {
          logViolation(`Away from test window for ${awaySec}s`);
          showTabWarning();
        }
        persistSession(true);
      }
    }

    document.addEventListener("visibilitychange", function () {
      if (document.hidden) handleLeaveScreen("hidden");
      else handleReturnScreen();
    });
    window.addEventListener("blur", function () { handleLeaveScreen("blur"); });
    window.addEventListener("focus", function () { handleReturnScreen(); });

    // 2. Fullscreen exit tracking
    document.addEventListener("fullscreenchange", function () {
      if (isExamActive() && !document.fullscreenElement) {
        const tel = ensureTelemetryObj();
        tel.fullscreenExits = (tel.fullscreenExits || 0) + 1;
        logViolation("Exited Fullscreen Mode");
      }
    });

    // 3. Block copying exam questions & right-click inside exam screen
    document.addEventListener("copy", function (e) {
      if (!isExamActive()) return;
      if (AC.BLOCK_QUESTION_COPY !== false) {
        e.preventDefault();
        const tel = ensureTelemetryObj();
        tel.copyAttempts = (tel.copyAttempts || 0) + 1;
        logViolation("Attempted to Copy Question Text");
        showSecToast("🚫 Copying exam questions is disabled & logged!");
      }
    });

    document.addEventListener("contextmenu", function (e) {
      if (!isExamActive()) return;
      if (e.target && e.target.tagName !== "TEXTAREA") {
        e.preventDefault();
      }
    });

    // 4. Keyboard shortcuts trap (PrintScreen, F12, Ctrl+Shift+I) + Keystroke telemetry in textarea
    document.addEventListener("keydown", function (e) {
      if (!isExamActive()) return;
      const tel = ensureTelemetryObj();

      if (
        e.key === "PrintScreen" ||
        e.key === "F12" ||
        ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === "I" || e.key === "i" || e.key === "J" || e.key === "C"))
      ) {
        tel.suspiciousShortcuts = (tel.suspiciousShortcuts || 0) + 1;
        logViolation(`Shortcut pressed: ${e.key}`);
        showSecToast("⚠️ Screenshot / Inspector shortcut logged!");
      }

      // Track genuine typing inside openTextarea
      if (e.target && e.target.id === "openTextarea") {
        const qId = currentQuestionId();
        const st = tel.openStats[qId] || { keystrokes: 0, backspaces: 0, typingMs: 0, pasteCount: 0, burstCount: 0 };
        if (e.key === "Backspace" || e.key === "Delete") {
          st.backspaces++;
        } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) {
          st.keystrokes++;
          const now = Date.now();
          if (lastKeyTimeMs > 0 && now - lastKeyTimeMs < 5000) {
            st.typingMs += now - lastKeyTimeMs;
          }
          lastKeyTimeMs = now;
        }
        tel.openStats[qId] = st;
      }
    });

    // 5. Block Paste & Drag-Drop into Open-Ended Textarea
    const ta = $("openTextarea");
    if (ta) {
      ta.addEventListener("paste", function (e) {
        if (!isExamActive()) return;
        const tel = ensureTelemetryObj();
        const qId = currentQuestionId();
        const st = tel.openStats[qId] || { keystrokes: 0, backspaces: 0, typingMs: 0, pasteCount: 0, burstCount: 0 };
        const clipText = (e.clipboardData && e.clipboardData.getData("text")) || "";

        tel.pasteAttempts = (tel.pasteAttempts || 0) + 1;
        st.pasteCount++;
        tel.openStats[qId] = st;
        logViolation(`Paste attempt on ${qId} (${clipText.length} chars)`);

        if (AC.BLOCK_OPEN_PASTE !== false) {
          e.preventDefault();
          showSecToast("🚫 Paste blocked! Type in your own words (Logged to Manager).");
          showTabWarning();
        }
      });

      ta.addEventListener("drop", function (e) {
        if (!isExamActive()) return;
        if (AC.BLOCK_OPEN_PASTE !== false) {
          e.preventDefault();
          const tel = ensureTelemetryObj();
          tel.pasteAttempts = (tel.pasteAttempts || 0) + 1;
          logViolation(`Drag-drop text blocked on ${currentQuestionId()}`);
          showSecToast("🚫 Drag-and-drop text is disabled!");
        }
      });
    }
  }

  window.setLangMode = function (mode) {
    document.body.classList.remove("lang-en", "lang-ur");
    if (mode === "en") document.body.classList.add("lang-en");
    if (mode === "ur") document.body.classList.add("lang-ur");
    document.querySelectorAll(".lang-switch button").forEach((btn) => {
      btn.classList.toggle("active", btn.getAttribute("data-lang") === mode);
    });
  };

  function showScreen(id) {
    ["screenWelcome", "screenInstructions", "screenExam", "screenResult"].forEach((s) => {
      const el = $(s);
      if (el) {
        el.classList.toggle("hidden", s !== id);
      }
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  /* ---------- Registration & Retake Check ---------- */
  window.handleRegistration = async function (e) {
    if (e && e.preventDefault) e.preventDefault();
    const errEl = $("regError");
    if (errEl) errEl.style.display = "none";

    const dSel = $("inpDealershipSelect");
    const dealershipId = dSel && dSel.value ? parseInt(dSel.value, 10) : null;
    const dealershipName = dSel && dSel.selectedIndex >= 0 && dSel.options[dSel.selectedIndex]
      ? (dSel.options[dSel.selectedIndex].getAttribute("data-name") || dSel.options[dSel.selectedIndex].textContent.trim())
      : "Pak Suzuki Dealership";

    const nameVal = ($("inpName") && $("inpName").value.trim()) || "";
    const phoneVal = ($("inpPhone") && $("inpPhone").value.trim()) || "";
    const phoneClean = phoneVal.replace(/\D/g, "");
    const empIdVal = ($("inpEmpId") && $("inpEmpId").value.trim().toUpperCase()) || phoneClean || ("PS-" + Date.now().toString().slice(-6));

    if (!nameVal || !phoneVal) {
      if (errEl) {
        errEl.innerHTML = "Please enter your Name and Mobile / WhatsApp number. • برائے مہربانی اپنا نام اور موبائل نمبر درج کریں۔";
        errEl.style.display = "block";
      }
      return;
    }

    const candidate = {
      dealershipId: dealershipId,
      dealershipName: dealershipName,
      name: nameVal,
      empId: empIdVal,
      phone: phoneVal,
      branch: $("inpBranch") ? $("inpBranch").value : "Main Showroom",
      role: $("inpRole") ? $("inpRole").value : "Sales Consultant",
      experience: $("inpExp") ? $("inpExp").value : "1 - 3 years"
    };

    const btn = $("btnProceedInstructions");
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Checking details...";
    }

    if (CFG.ALLOW_RETAKE === false) {
      const existingLocal = getLocalResults().find(
        (r) => String(r.empId).toUpperCase() === candidate.empId || (r.phone && r.phone.replace(/\D/g, "") === phoneClean)
      );
      if (existingLocal) {
        if (errEl) {
          errEl.innerHTML =
            `Candidate (Mobile: <strong>${escapeHtml(candidate.phone)}</strong>) has already completed this assessment (Score: ${existingLocal.qualityScore}/100). Retakes are disabled.<br/><span class="ur">آپ اس موبائل نمبر سے ٹیسٹ مکمل کر چکے ہیں۔ دوبارہ ٹیسٹ دینے کی اجازت نہیں ہے۔</span>`;
          errEl.style.display = "block";
        }
        if (btn) {
          btn.disabled = false;
          btn.textContent = "Continue to Instructions • آگے بڑھیں →";
        }
        return;
      }

      const checkUrl = CFG.CHECK_EMP_URL 
        ? `${CFG.CHECK_EMP_URL}?empId=${encodeURIComponent(candidate.empId)}&phone=${encodeURIComponent(candidate.phone)}`
        : (CFG.SCRIPT_URL ? `${CFG.SCRIPT_URL}?action=checkEmp&empId=${encodeURIComponent(candidate.empId)}` : null);

      if (checkUrl) {
        try {
          const controller = new AbortController();
          const timerId = setTimeout(() => controller.abort(), 2000);
          const resp = await fetch(checkUrl, { signal: controller.signal });
          clearTimeout(timerId);
          if (resp && resp.ok) {
            const data = await resp.json();
            if (data && data.exists) {
              const dealerInfo = data.dealership ? ` at ${escapeHtml(data.dealership)}` : '';
              const scoreInfo = data.score !== undefined ? ` (Score: ${data.score}/100)` : '';
              if (errEl) {
                errEl.innerHTML =
                  `Candidate (Mobile: <strong>${escapeHtml(candidate.phone)}</strong>) has already completed this assessment${scoreInfo}${dealerInfo}. Retakes are disabled.<br/><span class="ur">آپ اس موبائل نمبر سے ٹیسٹ مکمل کر چکے ہیں۔ دوبارہ ٹیسٹ دینے کی اجازت نہیں ہے۔</span>`;
                errEl.style.display = "block";
              }
              if (btn) {
                btn.disabled = false;
                btn.textContent = "Continue to Instructions • آگے بڑھیں →";
              }
              return;
            }
          }
        } catch (err) {
          console.warn("Retake pre-check skipped or timed out:", err);
        }
      }
    }

    if (btn) {
      btn.disabled = false;
      btn.textContent = "Continue to Instructions • آگے بڑھیں →";
    }

    try {
      state = buildNewSession(candidate);
      showScreen("screenInstructions");
    } catch (err) {
      console.error("Failed to build test session:", err);
      alert("Error starting test: " + err.message);
    }
  };

  window.backToWelcome = function () {
    showScreen("screenWelcome");
  };

  function buildNewSession(candidate) {
    const rng = mulberry32(hashSeed(candidate.empId + "_FRONX2026"));
    const sectionOrder = ["A", "B", "C", "F", "D", "E"];
    const items = [];

    sectionOrder.forEach((secKey) => {
      const secMcqs = BANK.mcq.filter((q) => q.s === secKey);
      const shuffledMcqs = seededShuffle(secMcqs, rng);
      shuffledMcqs.forEach((q) => {
        const idxArr = seededShuffle([0, 1, 2, 3], rng);
        items.push({
          type: "mcq",
          id: q.id,
          s: q.s,
          q: q.q,
          qu: q.qu,
          optMap: idxArr
        });
      });
    });

    BANK.open.forEach((oq) => {
      items.push({
        type: "open",
        id: oq.id,
        s: "O",
        q: oq.q,
        qu: oq.qu,
        hint: oq.hint,
        hintu: oq.hintu,
        max: oq.max || 5
      });
    });

    const totalSec = (CFG.TEST_DURATION_MIN || 50) * 60;
    return {
      submissionId: "SUB-" + Date.now() + "-" + Math.floor(rng() * 9000 + 1000),
      candidate,
      items,
      currentIndex: 0,
      mcqAnswers: {},
      openAnswers: {},
      flagged: {},
      tabSwitches: 0,
      telemetry: {
        copyAttempts: 0,
        pasteAttempts: 0,
        burstInserts: 0,
        focusLosses: 0,
        awayTotalSec: 0,
        longestAwaySec: 0,
        fullscreenExits: 0,
        suspiciousShortcuts: 0,
        fastMcqCount: 0,
        qAwaySec: {},
        openStats: {},
        violationLog: []
      },
      totalSec,
      remainingSec: totalSec,
      started: false,
      startedAt: null,
      submitted: false
    };
  }

  window.resumeSavedSession = function () {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_ACTIVE));
      if (saved && saved.items) {
        state = saved;
        ensureTelemetryObj();
        launchActiveExam();
      }
    } catch (e) {}
  };

  window.discardSavedSession = function () {
    localStorage.removeItem(STORAGE_ACTIVE);
    $("resumeBanner").classList.add("hidden");
  };

  window.startExamNow = function () {
    state.started = true;
    state.startedAt = new Date().toISOString();
    ensureTelemetryObj();
    persistSession();

    // Optional Fullscreen request
    if (AC.REQUEST_FULLSCREEN && document.documentElement.requestFullscreen) {
      document.documentElement.requestFullscreen().catch(() => {});
    }

    launchActiveExam();
  };

  function launchActiveExam() {
    showScreen("screenExam");
    $("timerBox").classList.remove("hidden");
    $("saveStatus").classList.remove("hidden");
    const tel = ensureTelemetryObj();
    if (state.tabSwitches > 0 || tel.pasteAttempts > 0) showTabWarning();
    renderPalette();
    renderCurrentQuestion();
    startTimer();
  }

  /* ---------- Timer & Auto-Save ---------- */
  function formatSec(sec) {
    const s = Math.max(0, Math.floor(sec));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return String(m).padStart(2, "0") + ":" + String(r).padStart(2, "0");
  }

  function startTimer() {
    if (timerInterval) clearInterval(timerInterval);
    $("timerText").textContent = formatSec(state.remainingSec);

    timerInterval = setInterval(() => {
      if (!state || state.submitted) {
        clearInterval(timerInterval);
        return;
      }
      state.remainingSec -= 1;
      $("timerText").textContent = formatSec(state.remainingSec);
      $("timerBox").classList.toggle("urgent", state.remainingSec <= 180);

      if (state.remainingSec % 5 === 0) persistSession(true);

      if (state.remainingSec <= 0) {
        clearInterval(timerInterval);
        submitFinalExam(true);
      }
    }, 1000);
  }

  function persistSession(silent) {
    if (!state || state.submitted) return;
    try {
      localStorage.setItem(STORAGE_ACTIVE, JSON.stringify(state));
      if (!silent) {
        const badge = $("saveStatus");
        badge.textContent = "✓ Auto-Saved • محفوظ ہو گیا";
        badge.style.opacity = "1";
      }
    } catch (e) {}
  }

  function showTabWarning() {
    const el = $("tabWarningBanner");
    const tel = ensureTelemetryObj();
    const violations = (state.tabSwitches || 0) + (tel.pasteAttempts || 0);
    const penalty = violations * (CFG.TAB_SWITCH_PENALTY || 2);
    el.innerHTML =
      `⚠️ <strong>Proctor Alert:</strong> ${state.tabSwitches || 0} tab switch(es), ${tel.awayTotalSec || 0}s away, ${tel.pasteAttempts || 0} paste attempt(s) recorded (-${Math.min(10, penalty)} Focus pts). All activity is visible to Management.<br/>` +
      `<span class="ur">توجہ فرمائیں: اسکرین چھوڑنے یا کاپی/پیسٹ کی کوشش ریکارڈ ہو رہی ہے اور مینجمنٹ رپورٹ میں شامل ہوگی۔</span>`;
    el.style.display = "block";
  }

  /* ---------- Rendering Question & Palette ---------- */
  function isItemAnswered(item) {
    if (item.type === "mcq") return state.mcqAnswers[item.id] !== undefined;
    return (state.openAnswers[item.id] || "").trim().length > 0;
  }

  function renderCurrentQuestion() {
    qViewStartMs = Date.now();
    lastKeyTimeMs = 0;

    const idx = state.currentIndex;
    const item = state.items[idx];
    const total = state.items.length;

    const secInfo = BANK.sections[item.s] || { en: "Section " + item.s, ur: "" };
    $("qSectionBadge").textContent = `${item.s}: ${secInfo.en}`;
    $("qCounterText").textContent = `Question ${idx + 1} of ${total}`;

    $("qTextEn").textContent = item.q;
    $("qTextUr").textContent = item.qu || "";

    const isFlagged = !!state.flagged[item.id];
    const flagBtn = $("btnFlag");
    flagBtn.classList.toggle("flagged", isFlagged);
    flagBtn.textContent = isFlagged ? "⚑ Marked for Review" : "⚑ Mark Review";

    $("btnPrev").disabled = idx === 0;
    const isLast = idx === total - 1;
    $("btnNext").classList.toggle("hidden", isLast);
    $("btnFinishOpen").classList.toggle("hidden", !isLast);

    if (item.type === "mcq") {
      $("mcqContainer").classList.remove("hidden");
      $("openContainer").classList.add("hidden");

      const origQ = BANK.mcq.find((m) => m.id === item.id);
      const selectedOrig = state.mcqAnswers[item.id];
      const letters = ["A", "B", "C", "D"];

      $("mcqContainer").innerHTML = item.optMap
        .map((origIdx, dispIdx) => {
          const isSel = selectedOrig === origIdx;
          const enTxt = origQ.o[origIdx] || "";
          const urTxt = (origQ.ou && origQ.ou[origIdx]) || "";
          return `
            <div class="option-card ${isSel ? "selected" : ""}" onclick="selectMcqOption('${item.id}', ${origIdx})">
              <div class="option-letter">${letters[dispIdx]}</div>
              <div class="option-body">
                <div class="option-en en">${escapeHtml(enTxt)}</div>
                <div class="option-ur ur">${escapeHtml(urTxt)}</div>
              </div>
            </div>
          `;
        })
        .join("");
    } else {
      $("mcqContainer").classList.add("hidden");
      $("openContainer").classList.remove("hidden");
      $("openHintEn").textContent = "💡 Tip: " + (item.hint || "");
      $("openHintUr").textContent = item.hintu || "";
      const val = state.openAnswers[item.id] || "";
      $("openTextarea").value = val;
      updateOpenFeedback(val);
    }

    updateProgressAndPalette();
  }

  window.selectMcqOption = function (qId, origIdx) {
    const firstTimeAnswering = state.mcqAnswers[qId] === undefined;
    if (firstTimeAnswering && Date.now() - qViewStartMs < 2200) {
      const tel = ensureTelemetryObj();
      tel.fastMcqCount = (tel.fastMcqCount || 0) + 1;
    }
    state.mcqAnswers[qId] = origIdx;
    persistSession();
    renderCurrentQuestion();
  };

  window.handleOpenInput = function (val) {
    const item = state.items[state.currentIndex];
    if (!item || item.type !== "open") return;

    const prev = state.openAnswers[item.id] || "";
    const delta = val.length - prev.length;

    // Catch sudden text burst (mobile clipboard chip / voice paste / autofill)
    if (delta >= 28) {
      const tel = ensureTelemetryObj();
      tel.burstInserts = (tel.burstInserts || 0) + 1;
      const st = tel.openStats[item.id] || { keystrokes: 0, backspaces: 0, typingMs: 0, pasteCount: 0, burstCount: 0 };
      st.burstCount = (st.burstCount || 0) + 1;
      tel.openStats[item.id] = st;
      logViolation(`Sudden text burst (+${delta} chars) in ${item.id}`);

      if (AC.BLOCK_OPEN_PASTE !== false) {
        $("openTextarea").value = prev;
        showSecToast("🚫 Clipboard / auto-insert blocked! Please type manually.");
        return;
      }
    }

    state.openAnswers[item.id] = val;
    updateOpenFeedback(val);
    persistSession();
    updateProgressAndPalette();
  };

  function updateOpenFeedback(val) {
    const words = (val || "").trim().split(/\s+/).filter(Boolean).length;
    $("openWordCount").textContent = `${words} words`;
    const fb = $("openDepthFeedback");
    if (words === 0) {
      fb.textContent = "Pending response • جواب باقی ہے";
      fb.style.color = "var(--slate-500)";
    } else if (words < 18) {
      fb.textContent = "Too brief — add specific features & customer benefits! • مزید تفصیل لکھیں";
      fb.style.color = "var(--gold)";
    } else if (words < 40) {
      fb.textContent = "Good start — add 1–2 more strong comparison points • اچھا جواب";
      fb.style.color = "var(--suzuki-blue)";
    } else {
      fb.textContent = "✓ Comprehensive Showroom Pitch! • بہترین تفصیلی جواب";
      fb.style.color = "var(--green)";
    }
  }

  window.toggleFlagCurrent = function () {
    const item = state.items[state.currentIndex];
    if (state.flagged[item.id]) delete state.flagged[item.id];
    else state.flagged[item.id] = true;
    persistSession();
    renderCurrentQuestion();
  };

  window.navQuestion = function (delta) {
    const next = state.currentIndex + delta;
    if (next >= 0 && next < state.items.length) {
      state.currentIndex = next;
      persistSession(true);
      renderCurrentQuestion();
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  window.jumpToQuestion = function (idx) {
    state.currentIndex = idx;
    persistSession(true);
    renderCurrentQuestion();
  };

  function renderPalette() {
    $("paletteGrid").innerHTML = state.items
      .map((item, i) => {
        const label = item.type === "open" ? item.id : i + 1;
        return `<button type="button" id="pbtn_${i}" class="p-btn ${item.type === "open" ? "open-q" : ""}" onclick="jumpToQuestion(${i})">${label}</button>`;
      })
      .join("");
  }

  function updateProgressAndPalette() {
    let answered = 0;
    state.items.forEach((item, i) => {
      const done = isItemAnswered(item);
      if (done) answered++;
      const btn = $("pbtn_" + i);
      if (btn) {
        btn.classList.toggle("done", done && !state.flagged[item.id]);
        btn.classList.toggle("flagged", !!state.flagged[item.id]);
        btn.classList.toggle("current", i === state.currentIndex);
      }
    });

    const total = state.items.length;
    const pct = Math.round((answered / total) * 100);
    $("examProgressFill").style.width = pct + "%";
    $("paletteSummary").textContent = `${answered} / ${total} Done`;
  }

  /* ---------- Pre-Submit Review Modal ---------- */
  window.openReviewModal = function () {
    const totalMcq = BANK.mcq.length;
    const doneMcq = Object.keys(state.mcqAnswers).length;
    const totalOpen = BANK.open.length;
    const doneOpen = BANK.open.filter((o) => (state.openAnswers[o.id] || "").trim().length > 0).length;
    const flaggedCount = Object.keys(state.flagged).length;
    const missing = (totalMcq - doneMcq) + (totalOpen - doneOpen);

    $("modalStatsGrid").innerHTML = `
      <div class="kpi-mini"><div class="lbl">MCQs Answered</div><div class="val">${doneMcq} / ${totalMcq}</div></div>
      <div class="kpi-mini"><div class="lbl">Pitch Qs Written</div><div class="val">${doneOpen} / ${totalOpen}</div></div>
      <div class="kpi-mini"><div class="lbl">Marked for Review</div><div class="val">${flaggedCount}</div></div>
    `;

    const warn = $("modalUnansweredWarn");
    if (missing > 0) {
      warn.innerHTML =
        `⚠️ You still have <strong>${missing}</strong> unanswered question(s). Unanswered items score 0 marks.<br/>` +
        `<span class="ur">آپ کے <strong>${missing}</strong> سوالات کے جواب ابھی باقی ہیں۔</span>`;
      warn.style.display = "block";
    } else {
      warn.style.display = "none";
    }

    $("reviewModal").classList.add("open");
  };

  window.closeReviewModal = function () {
    $("reviewModal").classList.remove("open");
  };

  /* ---------- AI Writing & Anti-Cheat Forensic Analyzer ---------- */
  const AI_PHRASES_EN = [
    "furthermore", "moreover", "in conclusion", "delve", "testament to",
    "compelling proposition", "seamlessly integrates", "stands out as",
    "notably", "comprehensive suite", "cater to", "when it comes to",
    "boasts a", "elevates the driving", "unparalleled", "cutting-edge",
    "game-changer", "strike a balance", "in summary", "pivotal role"
  ];
  const AI_PHRASES_UR = [
    "مزید برآں", "خلاصہ یہ کہ", "بلاشبہ", "قابلِ ذکر بات یہ ہے",
    "ہم آہنگی کے ساتھ", "مجموعی طور پر یہ کہا جا سکتا ہے", "شہکار ہے"
  ];

  function analyzeOpenAnswerForensics(qId, rawText, openStat, qAwaySec) {
    const text = String(rawText || "").trim();
    const signals = [];
    let risk = 0;

    if (!text) {
      return { aiRisk: 0, signals: [], wpm: 0, keystrokes: 0, backspaces: 0 };
    }

    const lower = text.toLowerCase();
    const words = text.split(/\s+/).filter(Boolean).length;
    const chars = text.length;
    const st = openStat || { keystrokes: 0, backspaces: 0, typingMs: 0, pasteCount: 0, burstCount: 0 };

    // 1. Markdown formatting (ChatGPT bold **headers** or ###)
    if (/\*\*[^*]+\*\*/.test(text) || /^#{1,3}\s/m.test(text)) {
      risk += 45;
      signals.push("Markdown formatting (**bold**/###) typical of ChatGPT");
    }

    // 2. LLM vocabulary fingerprint
    const matchedPhrases = [];
    AI_PHRASES_EN.forEach((p) => { if (lower.includes(p)) matchedPhrases.push(p); });
    AI_PHRASES_UR.forEach((p) => { if (text.includes(p)) matchedPhrases.push(p); });
    if (matchedPhrases.length >= 2) {
      risk += 45;
      signals.push(`AI vocabulary (${matchedPhrases.slice(0, 3).join(", ")})`);
    } else if (matchedPhrases.length === 1) {
      risk += 20;
      signals.push(`Formal AI phrase ("${matchedPhrases[0]}")`);
    }

    // 3. Keystroke-to-character ratio (catches paste / auto-fill / external IME dump)
    // Note: Urdu mobile keyboards can use word suggestions (ratio ~0.35-0.6), English desktop ~0.85+
    const keyRatio = chars > 0 ? st.keystrokes / chars : 1;
    if (chars >= 90 && st.keystrokes < 15) {
      risk += 55;
      signals.push(`Written without typing (${chars} chars vs only ${st.keystrokes} keys)`);
    } else if (chars >= 140 && keyRatio < 0.28) {
      risk += 35;
      signals.push(`Low keystroke ratio (${Math.round(keyRatio * 100)}%)`);
    }

    // 4. Zero backspaces on long answer (copying word-for-word from second phone)
    if (words >= 48 && st.backspaces === 0 && st.keystrokes > 50) {
      risk += 25;
      signals.push(`Long ${words}-word text typed with 0 corrections/backspaces`);
    }

    // 5. Typing speed WPM check
    const mins = (st.typingMs || 0) / 60000;
    const wpm = mins > 0.08 ? Math.round(words / mins) : 0;
    if (wpm > 115 && words >= 25) {
      risk += 35;
      signals.push(`Superhuman speed (${wpm} WPM)`);
    }

    // 6. Paste / Burst attempts on this question
    if (st.pasteCount > 0 || st.burstCount > 0) {
      risk += 40;
      signals.push(`${st.pasteCount} paste + ${st.burstCount} clipboard burst attempt(s)`);
    }

    // 7. Left screen for >= 20s while on this open question (went to ChatGPT/WhatsApp)
    if ((qAwaySec || 0) >= 20) {
      risk += 30;
      signals.push(`Left test screen for ${qAwaySec}s during this question`);
    }

    return {
      aiRisk: Math.min(100, risk),
      signals,
      wpm,
      keystrokes: st.keystrokes || 0,
      backspaces: st.backspaces || 0,
      pasteCount: st.pasteCount || 0,
      awaySec: qAwaySec || 0
    };
  }

  function buildOverallForensics(openForensicsMap) {
    const tel = ensureTelemetryObj();
    let score = 0;
    const flags = [];

    if ((tel.copyAttempts || 0) > 0) {
      score += Math.min(30, tel.copyAttempts * 12);
      flags.push(`Tried to copy exam questions ${tel.copyAttempts} time(s)`);
    }
    if ((tel.pasteAttempts || 0) > 0 || (tel.burstInserts || 0) > 0) {
      const tot = (tel.pasteAttempts || 0) + (tel.burstInserts || 0);
      score += Math.min(45, tot * 18);
      flags.push(`Attempted paste / clipboard injection ${tot} time(s)`);
    }
    if ((state.tabSwitches || 0) >= 2) {
      score += Math.min(35, state.tabSwitches * 9);
      flags.push(`Switched browser tabs / apps ${state.tabSwitches} times`);
    } else if ((state.tabSwitches || 0) === 1) {
      score += 10;
      flags.push(`Switched tab 1 time`);
    }
    if ((tel.awayTotalSec || 0) >= 25) {
      score += Math.min(35, Math.round(tel.awayTotalSec / 2.5));
      flags.push(`Spent ${tel.awayTotalSec}s outside test window (longest: ${tel.longestAwaySec}s)`);
    }
    if ((tel.suspiciousShortcuts || 0) > 0) {
      score += 15;
      flags.push(`Pressed Screenshot / Inspector shortcut (${tel.suspiciousShortcuts}x)`);
    }
    if ((tel.fastMcqCount || 0) >= 8) {
      score += 20;
      flags.push(`Answered ${tel.fastMcqCount} MCQs in under 2.2 seconds each`);
    }

    // Aggregate per-question AI risk
    const flaggedQs = [];
    Object.keys(openForensicsMap).forEach((qId) => {
      const f = openForensicsMap[qId];
      if (f.aiRisk >= 40) flaggedQs.push(`${qId} (${f.aiRisk}%)`);
    });
    if (flaggedQs.length > 0) {
      score += Math.min(55, flaggedQs.length * 22);
      flags.push(`AI / Assisted writing suspected on: ${flaggedQs.join(", ")}`);
    }

    const cheatRiskPct = Math.min(100, score);
    let integrityStatus = "CLEAN";
    let statusLabel = "Verified Human";
    if (cheatRiskPct >= 60) {
      integrityStatus = "HIGH_RISK";
      statusLabel = "High AI / Cheat Risk";
    } else if (cheatRiskPct >= 25) {
      integrityStatus = "SUSPICIOUS";
      statusLabel = "Suspicious – Review";
    }

    return {
      cheatRiskPct,
      integrityStatus,
      statusLabel,
      copyAttempts: tel.copyAttempts || 0,
      pasteAttempts: (tel.pasteAttempts || 0) + (tel.burstInserts || 0),
      awayTotalSec: tel.awayTotalSec || 0,
      longestAwaySec: tel.longestAwaySec || 0,
      fullscreenExits: tel.fullscreenExits || 0,
      fastMcqCount: tel.fastMcqCount || 0,
      flags,
      violationLog: tel.violationLog || []
    };
  }

  /* ---------- Quality Scoring Engine ---------- */
  function scoreOpenQuestion(oq, rawAnswer) {
    const text = String(rawAnswer || "").trim().toLowerCase();
    if (!text) return 0;

    const words = text.split(/\s+/).filter(Boolean);
    if (words.length < 4) return 0.5;

    let hits = 0;
    (oq.k || []).forEach((kw) => {
      if (text.includes(kw.toLowerCase())) hits++;
    });

    const conceptPts = Math.min(3.2, hits * 0.65);
    let depthPts = 0;
    if (words.length >= 45) depthPts = 1.8;
    else if (words.length >= 28) depthPts = 1.4;
    else if (words.length >= 16) depthPts = 0.9;
    else depthPts = 0.4;

    const rawScore = Math.min(oq.max || 5, conceptPts + depthPts);
    return Math.round(rawScore * 2) / 2;
  }

  function evaluateSubmission(autoTimedOut) {
    const weights = CFG.WEIGHTS || { mcq: 60, open: 30, discipline: 10 };
    const tel = ensureTelemetryObj();

    // 1. Section-wise & total MCQ accuracy
    const sectionStats = {};
    ["A", "B", "C", "F", "D", "E"].forEach((s) => {
      sectionStats[s] = {
        correct: 0, total: 0, pct: 0,
        name: (BANK.sections[s] && BANK.sections[s].en) || s,
        nameUr: (BANK.sections[s] && BANK.sections[s].ur) || ""
      };
    });

    let mcqCorrect = 0;
    const mcqDetails = {};
    BANK.mcq.forEach((q) => {
      const chosen = state.mcqAnswers[q.id];
      const isRight = chosen === q.a;
      if (isRight) {
        mcqCorrect++;
        sectionStats[q.s].correct++;
      }
      sectionStats[q.s].total++;
      mcqDetails[q.id] = {
        chosen: chosen !== undefined ? chosen : -1,
        correct: q.a,
        isRight
      };
    });

    Object.keys(sectionStats).forEach((s) => {
      const st = sectionStats[s];
      st.pct = st.total ? Math.round((st.correct / st.total) * 100) : 0;
    });

    const mcqWeighted = Math.round(((mcqCorrect / BANK.mcq.length) * weights.mcq) * 10) / 10;

    // 2. Open-ended provisional pitch score + per-question AI forensics
    let openRawSum = 0;
    let openMaxSum = 0;
    const openDetails = {};
    const openForensicsMap = {};

    BANK.open.forEach((oq) => {
      const ans = state.openAnswers[oq.id] || "";
      const pts = scoreOpenQuestion(oq, ans);
      const qFor = analyzeOpenAnswerForensics(
        oq.id,
        ans,
        tel.openStats && tel.openStats[oq.id],
        tel.qAwaySec && tel.qAwaySec[oq.id]
      );
      openForensicsMap[oq.id] = qFor;

      openRawSum += pts;
      openMaxSum += oq.max || 5;
      openDetails[oq.id] = {
        question: oq.q,
        answer: ans,
        autoPts: pts,
        maxPts: oq.max || 5,
        forensics: qFor
      };
    });

    const openWeighted = openMaxSum
      ? Math.round(((openRawSum / openMaxSum) * weights.open) * 10) / 10
      : 0;

    // 3. Discipline score (penalizes tab switches & paste attempts)
    const violationCount = (state.tabSwitches || 0) + (tel.pasteAttempts || 0);
    const penalty = violationCount * (CFG.TAB_SWITCH_PENALTY || 2);
    const disciplineWeighted = Math.max(0, weights.discipline - penalty);

    // 4. Overall Anti-Cheat & AI Forensics Report
    const forensics = buildOverallForensics(openForensicsMap);

    const qualityScore = Math.min(100, Math.round((mcqWeighted + openWeighted + disciplineWeighted) * 10) / 10);

    let grade = "D";
    let gradeLabel = "Grade D – Retraining Required";
    let gradeLabelUr = "گریڈ D – دوبارہ پراڈکٹ ٹریننگ درکار ہے";
    if (qualityScore >= 85) {
      grade = "A";
      gradeLabel = "Grade A – Certified Fronx Product Expert";
      gradeLabelUr = "گریڈ A – سرٹیفائیڈ فرونکس پراڈکٹ ایکسپرٹ (بہترین مہارت)";
    } else if (qualityScore >= 70) {
      grade = "B";
      gradeLabel = "Grade B – Proficient Sales Consultant";
      gradeLabelUr = "گریڈ B – اچھی مہارت (معمولی بہتری درکار)";
    } else if (qualityScore >= 52) {
      grade = "C";
      gradeLabel = "Grade C – Developing (Targeted Coaching Needed)";
      gradeLabelUr = "گریڈ C – اوسط مہارت (کمزور سیکشنز کی تیاری کریں)";
    }

    const timeTakenSec = state.totalSec - Math.max(0, state.remainingSec);

    return {
      submissionId: state.submissionId,
      submittedAt: new Date().toISOString(),
      autoTimedOut: !!autoTimedOut,
      dealershipId: state.candidate.dealershipId,
      dealershipName: state.candidate.dealershipName,
      name: state.candidate.name,
      empId: state.candidate.empId,
      phone: state.candidate.phone,
      branch: state.candidate.branch,
      role: state.candidate.role,
      experience: state.candidate.experience,
      qualityScore,
      grade,
      gradeLabel,
      gradeLabelUr,
      mcqCorrect,
      mcqTotal: BANK.mcq.length,
      mcqPct: Math.round((mcqCorrect / BANK.mcq.length) * 100),
      mcqWeighted,
      openRawSum,
      openMaxSum,
      openWeighted,
      managerReviewed: false,
      disciplineWeighted,
      tabSwitches: state.tabSwitches || 0,
      timeTakenSec,
      forensics,
      sectionStats,
      mcqDetails,
      openDetails
    };
  }

  window.submitFinalExam = async function (autoTimedOut) {
    if (!state || state.submitted) return;
    closeReviewModal();
    if (timerInterval) clearInterval(timerInterval);

    if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen().catch(() => {});
    }

    state.submitted = true;
    localStorage.removeItem(STORAGE_ACTIVE);
    $("timerBox").classList.add("hidden");
    $("saveStatus").classList.add("hidden");

    const resultRecord = evaluateSubmission(autoTimedOut);
    lastPayloadForSync = resultRecord;

    saveLocalResult(resultRecord);
    renderResultScreen(resultRecord);
    showScreen("screenResult");

    await syncToGoogleSheet(resultRecord);
  };

  async function syncToGoogleSheet(record) {
    const pill = $("cloudSyncPill");
    const retryBtn = $("btnRetrySync");

    // 1. Direct ROSP Portal API Submission
    if (CFG.SUBMIT_API_URL) {
      if (pill) {
        pill.textContent = "⏳ Saving to Dealership ROSP Portal Database...";
        pill.style.background = "#fef3c7";
        pill.style.color = "#92400e";
      }
      try {
        const resp = await fetch(CFG.SUBMIT_API_URL, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(record)
        });
        const data = await resp.json();
        if (data && (data.success || data.ok)) {
          record.cloudSynced = true;
          saveLocalResult(record);
          if (pill) {
            pill.textContent = `✓ Recorded in ${escapeHtml(record.dealershipName || 'Dealership')} Portal (DB Synced)`;
            pill.style.background = "#dcfce7";
            pill.style.color = "#166534";
          }
          if (retryBtn) retryBtn.classList.add("hidden");
          return;
        } else {
          throw new Error((data && data.error) || "Server rejected submission");
        }
      } catch (err) {
        console.warn("ROSP API Sync error:", err);
      }
    }

    // 2. Fallback to Google Sheet if configured
    if (CFG.SCRIPT_URL) {
      if (pill) {
        pill.textContent = "⏳ Saving to Dealership Google Sheet...";
        pill.style.background = "#fef3c7";
        pill.style.color = "#92400e";
      }
      try {
        const resp = await fetch(CFG.SCRIPT_URL, {
          method: "POST",
          headers: { "Content-Type": "text/plain;charset=utf-8" },
          body: JSON.stringify({ action: "submitTest", payload: record })
        });
        const data = await resp.json();
        if (data && data.ok) {
          record.cloudSynced = true;
          saveLocalResult(record);
          if (pill) {
            pill.textContent = "✓ Auto-Saved to Dealership Cloud Sheet";
            pill.style.background = "#dcfce7";
            pill.style.color = "#166534";
          }
          if (retryBtn) retryBtn.classList.add("hidden");
          return;
        }
      } catch (err) {}
    }

    // 3. Fallback to Local Result
    if (pill) {
      pill.textContent = "✓ Saved on Local Device (Click Retry to sync to Portal)";
      pill.style.background = "#fee2e2";
      pill.style.color = "#991b1b";
    }
    if (retryBtn) retryBtn.classList.remove("hidden");
  }

  window.retryCloudUpload = function () {
    if (lastPayloadForSync) syncToGoogleSheet(lastPayloadForSync);
  };

  /* ---------- Render Result Screen ---------- */
  function renderResultScreen(r) {
    $("resCandidateName").textContent = `${r.name} (${r.empId})`;
    $("resCandidateMeta").textContent =
      `${r.role} • ${r.branch} • Time Taken: ${Math.floor(r.timeTakenSec / 60)}m ${r.timeTakenSec % 60}s`;

    $("resGauge").style.setProperty("--pct", r.qualityScore + "%");
    $("resQualityScore").textContent = r.qualityScore;

    const badge = $("resGradeBadge");
    badge.className = "grade-pill grade-" + r.grade;
    badge.textContent = r.gradeLabel;

    $("resHeadlineEn").textContent =
      r.grade === "A"
        ? "Outstanding mastery of Fronx variants, features & competitor battlecards!"
        : r.grade === "B"
        ? "Solid product knowledge. Review the highlighted competitor sections below to reach 85+."
        : "Please study the Suzuki Fronx Master Comparison Sheet and strengthen weak areas below.";
    $("resHeadlineUr").textContent = r.gradeLabelUr;

    $("resMcqPts").textContent = `${r.mcqWeighted} / ${(CFG.WEIGHTS && CFG.WEIGHTS.mcq) || 60}`;
    $("resMcqRaw").textContent = `${r.mcqCorrect} of ${r.mcqTotal} correct (${r.mcqPct}%)`;
    $("resOpenPts").textContent = `${r.openWeighted} / ${(CFG.WEIGHTS && CFG.WEIGHTS.open) || 30}`;
    $("resDiscPts").textContent = `${r.disciplineWeighted} / ${(CFG.WEIGHTS && CFG.WEIGHTS.discipline) || 10}`;
    $("resTabInfo").textContent = `${r.tabSwitches} tab switch(es) • ${(r.forensics && r.forensics.awayTotalSec) || 0}s away`;

    const secHtml = ["A", "B", "C", "F", "D", "E"]
      .map((s) => {
        const st = r.sectionStats[s];
        const color = st.pct >= 80 ? "var(--green)" : st.pct >= 60 ? "var(--suzuki-blue)" : "var(--suzuki-red)";
        return `
          <div class="sec-bar-row">
            <div class="sec-bar-head">
              <span>${s}. ${escapeHtml(st.name)}</span>
              <span>${st.correct}/${st.total} (${st.pct}%)</span>
            </div>
            <div class="sec-bar-track">
              <div class="sec-bar-fill" style="width:${st.pct}%; background:${color};"></div>
            </div>
          </div>
        `;
      })
      .join("");
    $("resSectionBars").innerHTML = secHtml;

    const tips = [];
    if (r.sectionStats.A.pct < 75) {
      tips.push("<li><strong>Fronx Variants (GL vs GLX):</strong> Revise K15B (5MT/4AT) vs K15C DualJet SHVS Mild-Hybrid (6AT + Paddle Shifters) and Two-Tone / ADAS trim differences. <span class='ur'>فرونکس GL اور GLX کے انجن اور گیئر کا فرق دہرائیں۔</span></li>");
    }
    if (r.sectionStats.B.pct < 75) {
      tips.push("<li><strong>Hybrid & ADAS Demo Skills:</strong> Practice explaining HEARTECT high-tensile steel, SHVS Idle-Stop, 360° Camera, HUD, and 6-Airbag + ADAS benefits. <span class='ur'>مائلڈ ہائبرڈ، 360 کیمرہ، HUD اور ADAS فیچرز کی عملی مشق کریں۔</span></li>");
    }
    if (r.sectionStats.C.pct < 75) {
      tips.push("<li><strong>Crossovers & Sedans (Stonic, 2008, MG ZS, City, Yaris):</strong> Focus on ground clearance (170mm), 6 vs 2 airbags, Rear AC vents, and 4-cyl maintenance vs Peugeot 3-cyl turbo / Stonic DCT. <span class='ur'>اسٹونک، پیژو 2008، ایم جی، سٹی اور یارِس کا موازنہ مضبوط کریں۔</span></li>");
    }
    if (r.sectionStats.F.pct < 75) {
      tips.push("<li><strong>Chinese Brands (Jaecoo J5/J7, Omoda, BYD, Jetour, Chery, Changan):</strong> Master countering Jaecoo J5 BEV/HEV with zero charging anxiety, nationwide 3S parts network, liquid resale, and Rs. 15–30 Lakh TCO savings against higher Chinese SUVs. <span class='ur'>جیکو J5، BYD، اوموڈا، جیٹور اور چنگان کے مقابلے میں سوزوکی نیٹ ورک، چارجنگ سے آزادی اور ری سیل کے دلائل پختہ کریں۔</span></li>");
    }
    if (r.openWeighted < 21) {
      tips.push("<li><strong>Sales Pitch Articulation:</strong> Structure your showroom pitches using <em>Acknowledge → Compare Daily Benefit → Financial/Resale Proof → Offer Test Drive</em>. <span class='ur'>کسٹمر کو جواب دیتے وقت کمپیٹیٹر کی بات سن کر روزمرہ فائدے اور ٹیسٹ ڈرائیو پر فوکس کریں۔</span></li>");
    }
    if (!tips.length) {
      tips.push("<li>🌟 <strong>Ready for Floor Leadership:</strong> Your product & competitor mastery is top-tier. Help mentor junior executives during morning showroom huddles!</li>");
    }
    $("resCoachingList").innerHTML = `<ul style="padding-left:18px; display:flex; flex-direction:column; gap:10px;">${tips.join("")}</ul>`;

    if (CFG.SHOW_ANSWERS_AFTER_TEST) {
      $("resAnswerKeyCard").classList.remove("hidden");
      $("resAnswerKeyList").innerHTML = BANK.mcq
        .map((q, i) => {
          const det = r.mcqDetails[q.id];
          const ok = det && det.isRight;
          return `
            <div style="padding:12px 0; border-bottom:1px solid var(--slate-200); font-size:0.88rem;">
              <div style="font-weight:700; color:${ok ? "var(--green)" : "var(--suzuki-red)"};">
                ${ok ? "✓" : "✗"} Q${i + 1} (${q.id}): ${escapeHtml(q.q)}
              </div>
              <div style="margin-top:4px; color:var(--slate-700);"><strong>Correct:</strong> ${escapeHtml(q.o[q.a])}</div>
              <div style="margin-top:2px; color:var(--slate-500);">${escapeHtml(q.x || "")}</div>
            </div>
          `;
        })
        .join("");
    }
  }

  document.addEventListener("DOMContentLoaded", initPage);
})();
