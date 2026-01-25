let currentStep = 1;

let registrationData = {
  license: null,
  name: null,
  firstname: null,
  sex: null,
  dob: null,
  ioccode: null,
  club: null,
  countrycode: null,
  classified: null,

  email: null,
  sessions: [],
  sessionChoices: {}
};

window.addEventListener("DOMContentLoaded", function () {
  validateToken();
});

function validateToken() {
  const TOURNAMENTID = window.TOURNAMENTID;
  const ACCESSTOKEN = window.ACCESSTOKEN;

  if (!TOURNAMENTID || !ACCESSTOKEN) {
    document.body.innerHTML =
      '<div style="text-align:center;padding:50px;font-family:Arial;">' +
      "<h1>Accès refusé</h1><p>Paramètres manquants.</p></div>";
    return;
  }

  const formData = new FormData();
  formData.append("action", "validatetoken");
  formData.append("tournamentid", TOURNAMENTID);
  formData.append("token", ACCESSTOKEN);

  fetch("process.php", { method: "POST", body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        document.getElementById("tournament-name").textContent = data.data.tournamentname;
      } else {
        document.body.innerHTML =
          '<div style="text-align:center;padding:50px;font-family:Arial;">' +
          "<h1>Accès refusé</h1><p>" + (data.error || "Token invalide") + "</p></div>";
      }
    })
    .catch(() => {
      document.body.innerHTML =
        '<div style="text-align:center;padding:50px;font-family:Arial;">' +
        "<h1>Erreur</h1><p>Impossible de valider l'accès.</p></div>";
    });
}

function goToStep(step) {
  document.querySelectorAll(".form-step").forEach(el => el.classList.remove("active"));
  document.getElementById("step-" + step).classList.add("active");

  document.querySelectorAll(".progress-step").forEach(el => {
    const stepNum = parseInt(el.getAttribute("data-step"), 10);
    if (stepNum <= step) el.classList.add("active");
    else el.classList.remove("active");
  });

  currentStep = step;
  window.scrollTo(0, 0);
}

function nextStep(step) {
  if (step === 1) {
    const license = document.getElementById("license").value.trim();
    const lastname = document.getElementById("lastname").value.trim();
    const email = document.getElementById("email").value.trim();

    if (!license) return showError("Veuillez saisir votre numéro de licence");
    if (!lastname) return showError("Veuillez saisir votre nom de famille");
    if (!email) return showError("Veuillez saisir votre adresse email");

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) return showError("Veuillez saisir une adresse email valide");

    registrationData.email = email;
    return searchLicense(license, lastname);
  }

  if (step === 2) {
    const checkboxes = document.querySelectorAll('input[name="sessions"]:checked');
    if (checkboxes.length === 0) return showError("Veuillez sélectionner au moins un départ");

    registrationData.sessions = Array.from(checkboxes).map(cb => parseInt(cb.value, 10));
    registrationData.sessionChoices = {};
    buildSessionChoicesUI();
    goToStep(3);
    return;
  }

  if (step === 3) {
    for (const sessionId of registrationData.sessions) {
      const divSelect = document.getElementById(`division-session-${sessionId}`);
      const classSelect = document.getElementById(`ageclass-session-${sessionId}`);
      const tfSelect = document.getElementById(`targetface-session-${sessionId}`);

      if (!divSelect || !divSelect.value) return showError(`Veuillez sélectionner une division pour le départ ${sessionId}`);
      if (!classSelect || !classSelect.value) return showError(`Veuillez sélectionner une catégorie pour le départ ${sessionId}`);
      if (!tfSelect || !tfSelect.value) return showError(`Veuillez sélectionner un blason pour le départ ${sessionId}`);

      const existing = registrationData.sessionChoices[sessionId] || {};
      registrationData.sessionChoices[sessionId] = {
        division: divSelect.value,
        ageclass: classSelect.value,
        targetface: parseInt(tfSelect.value, 10),
        distanceLabel: existing.distanceLabel || null
      };
    }

    showSummary();
    goToStep(4);
    return;
  }
}

function prevStep(step) {
  goToStep(step - 1);
}

function showError(message) {
  const errorDiv = document.getElementById("error-message");
  errorDiv.textContent = message;
  errorDiv.style.display = "block";
  setTimeout(() => (errorDiv.style.display = "none"), 10000);
  window.scrollTo(0, 0);
}

function showSuccess(message) {
  const successDiv = document.getElementById("success-message");
  successDiv.textContent = message;
  successDiv.style.display = "block";
  window.scrollTo(0, 0);
}

function showLoading() { document.getElementById("loading").style.display = "flex"; }
function hideLoading() { document.getElementById("loading").style.display = "none"; }

function formatProperName(name) {
  if (!name) return "";
  return name.toLowerCase().replace(/\b\w/g, letter => letter.toUpperCase());
}

function postProcess(action, extraFormData) {
  const TOURNAMENTID = window.TOURNAMENTID;
  const formData = new FormData();
  formData.append("action", action);
  formData.append("tournamentid", TOURNAMENTID);
  if (extraFormData) {
    for (const [k, v] of extraFormData) formData.append(k, v);
  }
  return fetch("process.php", { method: "POST", body: formData }).then(r => r.json());
}

function searchLicense(license, lastname) {
  showLoading();

  postProcess("searchlicense", [
    ["license", license],
    ["lastname", lastname]
  ])
    .then(data => {
      hideLoading();
      if (!data.success) return showError(data.error || "Licence ou nom de famille incorrect");

      registrationData.license = data.data.license;
      registrationData.name = data.data.name;
      registrationData.firstname = data.data.firstname;
      registrationData.sex = data.data.sex;
      registrationData.dob = data.data.dob;
      registrationData.ioccode = data.data.ioccode;
      registrationData.club = data.data.club;
      registrationData.countrycode = data.data.countrycode;
      registrationData.classified = data.data.classified;

      displayArcherInfo();
      loadSessions();
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

function displayArcherInfo() {
  document.getElementById("info-name").textContent = formatProperName(registrationData.name);
  document.getElementById("info-firstname").textContent = formatProperName(registrationData.firstname);
  document.getElementById("info-dob").textContent = registrationData.dob;
  document.getElementById("info-sex").textContent = registrationData.sex === 0 ? "Homme" : "Femme";
  document.getElementById("info-club").textContent = registrationData.club;
}

function loadSessions() {
  showLoading();
  postProcess("getsessions")
    .then(data => {
      hideLoading();
      if (!data.success) return showError(data.error || "Erreur lors du chargement des départs");
      displaySessions(data.data);
      goToStep(2);
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

function displaySessions(sessions) {
  const container = document.getElementById("sessions-container");
  container.innerHTML = "";
  if (!sessions || sessions.length === 0) {
    container.innerHTML = "<p>Aucun départ disponible</p>";
    return;
  }
  sessions.forEach(session => {
    const div = document.createElement("div");
    div.className = "checkbox-item";
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.name = "sessions";
    checkbox.value = session.session;
    checkbox.id = "session-" + session.session;
    const label = document.createElement("label");
    label.htmlFor = checkbox.id;
    label.textContent = session.label;
    div.appendChild(checkbox);
    div.appendChild(label);
    container.appendChild(div);
  });
}

function loadDistanceForSession(sessionId, division, ageclass) {
  const distanceEl = document.getElementById(`distance-session-${sessionId}`);
  if (distanceEl) distanceEl.textContent = "Distance : …";

  const sexSuffix = (registrationData.sex === 0) ? "_M" : "_W"; // 0=Homme dans ton code [file:4]

  const call = (cls) => postProcess("getdistancebycategory", [
    ["division", division],
    ["class", cls]
  ]);

  call(ageclass)
    .then(data => {
      if (!data.success) throw new Error("api");
      const label = data.data?.label || "";
      if (label) {
        if (distanceEl) distanceEl.textContent = "Distance : " + label;
        return { label };
      }
      // fallback: forcer _M/_W pour matcher CLS_M / CLS_W
      return call(sexSuffix).then(d2 => {
        if (!d2.success) return { label: "" };
        return { label: d2.data?.label || "" };
      });
    })
    .then(({label}) => {
      if (distanceEl) distanceEl.textContent = label ? ("Distance : " + label) : "";
      const existing = registrationData.sessionChoices[sessionId] || {};
      registrationData.sessionChoices[sessionId] = { ...existing, division, ageclass, distanceLabel: label || null };
    })
    .catch(() => {
      if (distanceEl) distanceEl.textContent = "";
    });
}



function buildSessionChoicesUI() {
  const container = document.getElementById("session-choices-container");
  container.innerHTML = "";

  registrationData.sessions.forEach(sessionId => {
    const block = document.createElement("div");
    block.className = "targetface-session";

    const title = document.createElement("label");
    title.textContent = "Départ " + sessionId;
    block.appendChild(title);

    const divLabel = document.createElement("label");
    divLabel.textContent = "Division";
    divLabel.style.marginTop = "10px";
    block.appendChild(divLabel);

    const divSelect = document.createElement("select");
    divSelect.id = `division-session-${sessionId}`;
    divSelect.className = "form-control";
    divSelect.innerHTML = `<option value="">-- Choisir une division --</option>`;
    divSelect.addEventListener("change", () => {
      const classSelect = document.getElementById(`ageclass-session-${sessionId}`);
      const tfSelect = document.getElementById(`targetface-session-${sessionId}`);
      const distEl = document.getElementById(`distance-session-${sessionId}`);

      if (classSelect) classSelect.innerHTML = `<option value="">-- Choisir une catégorie --</option>`;
      if (tfSelect) tfSelect.innerHTML = `<option value="">-- Choisir un blason --</option>`;
      if (distEl) distEl.textContent = "";

      if (divSelect.value) loadClassesForSession(sessionId, divSelect.value);
    });
    block.appendChild(divSelect);

    const classLabel = document.createElement("label");
    classLabel.textContent = "Catégorie";
    classLabel.style.marginTop = "10px";
    block.appendChild(classLabel);

    const classSelect = document.createElement("select");
    classSelect.id = `ageclass-session-${sessionId}`;
    classSelect.className = "form-control";
    classSelect.innerHTML = `<option value="">-- Choisir une catégorie --</option>`;
    classSelect.addEventListener("change", () => {
      const tfSelect = document.getElementById(`targetface-session-${sessionId}`);
      const distEl = document.getElementById(`distance-session-${sessionId}`);

      if (tfSelect) tfSelect.innerHTML = `<option value="">-- Choisir un blason --</option>`;
      if (distEl) distEl.textContent = "";

      if (classSelect.value) {
        loadTargetFacesForSession(sessionId, divSelect.value, classSelect.value);
        loadDistanceForSession(sessionId, divSelect.value, classSelect.value);
      }
    });
    block.appendChild(classSelect);

    /* NOUVEAU: affichage distance dans le bloc du départ */
    const distanceInfo = document.createElement("div");
    distanceInfo.id = `distance-session-${sessionId}`;
    distanceInfo.className = "info-text";
    distanceInfo.style.marginTop = "10px";
    distanceInfo.textContent = "";
    block.appendChild(distanceInfo);

    const tfLabel = document.createElement("label");
    tfLabel.textContent = "Blason";
    tfLabel.style.marginTop = "10px";
    block.appendChild(tfLabel);

    const tfSelect = document.createElement("select");
    tfSelect.id = `targetface-session-${sessionId}`;
    tfSelect.className = "form-control";
    tfSelect.innerHTML = `<option value="">-- Choisir un blason --</option>`;
    block.appendChild(tfSelect);

    container.appendChild(block);
    loadDivisionsForSession(sessionId);
  });
}

function loadDivisionsForSession(sessionId) {
  showLoading();
  postProcess("getdivisions")
    .then(data => {
      hideLoading();
      if (!data.success) return showError(data.error || "Erreur lors du chargement des divisions");
      const select = document.getElementById(`division-session-${sessionId}`);
      if (!select) return;
      select.innerHTML = `<option value="">-- Choisir une division --</option>`;
      data.data.forEach(div => {
        const opt = document.createElement("option");
        opt.value = div.DivId;
        opt.textContent = div.DivDescription;
        select.appendChild(opt);
      });
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

function loadClassesForSession(sessionId, division) {
  showLoading();
  postProcess("getclasses", [
    ["division", division],
    ["dob", registrationData.dob],
    ["sex", registrationData.sex]
  ])
    .then(data => {
      hideLoading();
      if (!data.success) return showError(data.error || "Erreur lors du chargement des catégories");
      const select = document.getElementById(`ageclass-session-${sessionId}`);
      if (!select) return;
      select.innerHTML = `<option value="">-- Choisir une catégorie --</option>`;
      data.data.forEach(cl => {
        const opt = document.createElement("option");
        opt.value = cl.ClId;
        opt.textContent = cl.ClDescription;
        select.appendChild(opt);
      });
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

function loadTargetFacesForSession(sessionId, division, ageclass) {
  showLoading();
  postProcess("gettargetfaces", [
    ["division", division],
    ["class", ageclass]
  ])
    .then(data => {
      hideLoading();
      if (!data.success) return showError(data.error || "Erreur lors du chargement des blasons");
      const select = document.getElementById(`targetface-session-${sessionId}`);
      if (!select) return;
      select.innerHTML = `<option value="">-- Choisir un blason --</option>`;
      data.data.forEach(tf => {
        const opt = document.createElement("option");
        opt.value = tf.id;
        opt.textContent = tf.name;
        if (tf.default === 1) opt.selected = true;
        select.appendChild(opt);
      });
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

function showSummary() {
  const div = document.getElementById("summary-content");
  const sessionsLines = registrationData.sessions.map(sessionId => {
    const divSel = document.getElementById(`division-session-${sessionId}`);
    const classSel = document.getElementById(`ageclass-session-${sessionId}`);
    const tfSel = document.getElementById(`targetface-session-${sessionId}`);

    const divText = divSel ? divSel.options[divSel.selectedIndex].text : "";
    const classText = classSel ? classSel.options[classSel.selectedIndex].text : "";
    const tfText = tfSel ? tfSel.options[tfSel.selectedIndex].text : "";

    const distTxt = (registrationData.sessionChoices[sessionId] && registrationData.sessionChoices[sessionId].distanceLabel)
      ? (" - " + registrationData.sessionChoices[sessionId].distanceLabel)
      : "";

    return `<li>Départ ${sessionId} : ${divText} / ${classText} / ${tfText}${distTxt}</li>`;
  }).join("");

  div.innerHTML = `
    <p><strong>Licence :</strong> ${registrationData.license}</p>
    <p><strong>Nom :</strong> ${formatProperName(registrationData.name)}</p>
    <p><strong>Prénom :</strong> ${formatProperName(registrationData.firstname)}</p>
    <p><strong>Email :</strong> ${registrationData.email}</p>
    <p><strong>Club :</strong> ${registrationData.club}</p>
    <p><strong>Inscriptions :</strong></p>
    <ul>${sessionsLines}</ul>
  `;
}

function submitRegistration() {
  showLoading();

  const TOURNAMENTID = window.TOURNAMENTID;
  const formData = new FormData();
  formData.append("action", "submitregistration");
  formData.append("tournamentid", TOURNAMENTID);
  formData.append("data", JSON.stringify(registrationData));

  fetch("process.php", { method: "POST", body: formData })
    .then(r => r.json())
    .then(data => {
      hideLoading();
      if (data.success) {
        showSuccess("Inscription réussie ! Vous allez recevoir une confirmation par email.");
        setTimeout(() => location.reload(), 3000);
      } else {
        showError(data.error || "Erreur lors de l'inscription");
      }
    })
    .catch(err => {
      hideLoading();
      showError("Erreur réseau : " + err.message);
    });
}

