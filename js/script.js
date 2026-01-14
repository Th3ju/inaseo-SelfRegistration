let currentStep = 1;
let registrationData = {};

// Valider le token au chargement de la page
window.addEventListener('DOMContentLoaded', function() {
    validateToken();
});

function validateToken() {
    if (!TOURNAMENT_ID || !ACCESS_TOKEN) {
        document.body.innerHTML = '<div style="text-align: center; padding: 50px; font-family: Arial;"><h1>Accès refusé</h1><p>Paramètres manquants.</p></div>';
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'validate_token');
    formData.append('tournament_id', TOURNAMENT_ID);
    formData.append('token', ACCESS_TOKEN);
    
    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('tournament-name').textContent = data.data.tournament_name;
        } else {
            document.body.innerHTML = '<div style="text-align: center; padding: 50px; font-family: Arial;"><h1>Accès refusé</h1><p>' + (data.error || 'Token invalide') + '</p></div>';
        }
    })
    .catch(error => {
        document.body.innerHTML = '<div style="text-align: center; padding: 50px; font-family: Arial;"><h1>Erreur</h1><p>Impossible de valider l\'accès.</p></div>';
    });
}

function loadTournamentName() {
    const formData = new FormData();
    formData.append('action', 'get_tournament_name');
    formData.append('tournament_id', TOURNAMENT_ID);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('tournament-name').textContent = data.data.name;
        }
    })
    .catch(error => {
        console.error('Erreur chargement nom tournoi:', error);
    });
}

function goToStep(step) {
    // Cacher toutes les étapes
    document.querySelectorAll('.form-step').forEach(el => {
        el.classList.remove('active');
    });

    // Afficher l'étape demandée
    document.getElementById('step-' + step).classList.add('active');

    // Mettre à jour la barre de progression
    document.querySelectorAll('.progress-step').forEach(el => {
        const stepNum = parseInt(el.getAttribute('data-step'));
        if (stepNum <= step) {
            el.classList.add('active');
        } else {
            el.classList.remove('active');
        }
    });

    currentStep = step;
    window.scrollTo(0, 0);
}

function nextStep(step) {
    if (step === 1) {
        const license = document.getElementById('license').value.trim();
        const lastname = document.getElementById('lastname').value.trim();

        if (!license) {
            showError('Veuillez saisir votre numéro de licence');
            return;
        }

        if (!lastname) {
            showError('Veuillez saisir votre nom de famille');
            return;
        }

        searchLicense(license, lastname);
    } else if (step === 2) {
        const email = document.getElementById('email').value.trim();
        const division = document.getElementById('division').value;

        if (!email) {
            showError('Veuillez saisir votre adresse email');
            return;
        }

        // Validation simple de l'email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            showError('Veuillez saisir une adresse email valide');
            return;
        }

        if (!division) {
            showError('Veuillez sélectionner une division');
            return;
        }

        registrationData.email = email;
        registrationData.division = division;
        loadClasses();
    } else if (step === 3) {
        const ageclass = document.getElementById('ageclass').value;

        if (!ageclass) {
            showError('Veuillez sélectionner une catégorie');
            return;
        }

        registrationData.ageclass = ageclass;
        loadSessions();
    } else if (step === 4) {
        const checkboxes = document.querySelectorAll('input[name="sessions"]:checked');

        if (checkboxes.length === 0) {
            showError('Veuillez sélectionner au moins un départ');
            return;
        }

        registrationData.sessions = Array.from(checkboxes).map(cb => parseInt(cb.value));
        // Charger les blasons pour chaque départ
        loadTargetFacesForSessions();
    } else if (step === 5) {
        // Vérifier qu'un blason est sélectionné pour chaque départ
        const allSelected = registrationData.sessions.every(session => {
            const select = document.getElementById(`targetface-${session}`);
            return select && select.value;
        });

        if (!allSelected) {
            showError('Veuillez sélectionner un blason pour chaque départ');
            return;
        }

        // Collecter les blasons par départ
        registrationData.targetfaces = {};
        registrationData.sessions.forEach(session => {
            const select = document.getElementById(`targetface-${session}`);
            registrationData.targetfaces[session] = parseInt(select.value);
        });

        showSummary();
        goToStep(6);
    }
}

function prevStep(step) {
    goToStep(step - 1);
}

function showError(message) {
    const errorDiv = document.getElementById('error-message');
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
    setTimeout(() => {
        errorDiv.style.display = 'none';
    }, 5000);
    window.scrollTo(0, 0);
}

function showSuccess(message) {
    const successDiv = document.getElementById('success-message');
    successDiv.textContent = message;
    successDiv.style.display = 'block';
    window.scrollTo(0, 0);
}

function showLoading() {
    document.getElementById('loading').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loading').style.display = 'none';
}

function searchLicense(license, lastname) {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'search_license');
    formData.append('tournament_id', TOURNAMENT_ID);
    formData.append('license', license);
    formData.append('lastname', lastname);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            registrationData = {
                license: data.data.license,
                name: data.data.name,
                firstname: data.data.firstname,
                sex: data.data.sex,
                dob: data.data.dob,
                ioccode: data.data.ioccode,
                club: data.data.club,
                country_code: data.data.country_code,
                classified: data.data.classified
            };
            displayArcherInfo();
            loadDivisions();
        } else {
            showError(data.error || 'Licence ou nom de famille incorrect');
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}

// Fonction pour formater les noms (première lettre majuscule)
function formatProperName(name) {
    if (!name) return '';
    return name.toLowerCase().replace(/\b\w/g, function(letter) {
        return letter.toUpperCase();
    });
}

function displayArcherInfo() {
    document.getElementById('info-name').textContent = formatProperName(registrationData.name);
    document.getElementById('info-firstname').textContent = formatProperName(registrationData.firstname);
    document.getElementById('info-dob').textContent = registrationData.dob;
    document.getElementById('info-sex').textContent = registrationData.sex === 0 ? 'Homme' : 'Femme';
    document.getElementById('info-club').textContent = registrationData.club;
}

function loadDivisions() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'get_divisions');
    formData.append('tournament_id', TOURNAMENT_ID);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const select = document.getElementById('division');
            select.innerHTML = '<option value="">-- Choisir une division --</option>';
            data.data.forEach(div => {
                const option = document.createElement('option');
                option.value = div.DivId;
                option.textContent = div.DivDescription;
                select.appendChild(option);
            });
            goToStep(2);
        } else {
            showError(data.error || 'Erreur lors du chargement des divisions');
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}

function loadClasses() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'get_classes');
    formData.append('tournament_id', TOURNAMENT_ID);
    formData.append('division', registrationData.division);
    formData.append('dob', registrationData.dob);
    formData.append('sex', registrationData.sex);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const select = document.getElementById('ageclass');
            select.innerHTML = '<option value="">-- Choisir une catégorie --</option>';
            data.data.forEach(cl => {
                const option = document.createElement('option');
                option.value = cl.ClId;
                option.textContent = cl.ClDescription;
                select.appendChild(option);
            });
            goToStep(3);
        } else {
            showError(data.error || 'Erreur lors du chargement des catégories');
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}

function loadSessions() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'get_sessions');
    formData.append('tournament_id', TOURNAMENT_ID);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            displaySessions(data.data);
            goToStep(4);
        } else {
            showError(data.error || 'Erreur lors du chargement des départs');
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}

function displaySessions(sessions) {
    const container = document.getElementById('sessions-container');
    container.innerHTML = '';

    if (sessions.length === 0) {
        container.innerHTML = '<p>Aucun départ disponible</p>';
        return;
    }

    sessions.forEach(session => {
        const div = document.createElement('div');
        div.className = 'checkbox-item';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'sessions';
        checkbox.value = session.session;
        checkbox.id = 'session-' + session.session;

        const label = document.createElement('label');
        label.htmlFor = 'session-' + session.session;
        label.textContent = session.label;

        div.appendChild(checkbox);
        div.appendChild(label);
        container.appendChild(div);
    });
}

function loadTargetFacesForSessions() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'get_target_faces');
    formData.append('tournament_id', TOURNAMENT_ID);
    formData.append('division', registrationData.division);
    formData.append('class', registrationData.ageclass);

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            displayTargetFacesForSessions(data.data);
            goToStep(5);
        } else {
            showError(data.error || 'Erreur lors du chargement des blasons');
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}

function displayTargetFacesForSessions(targetfaces) {
    const container = document.getElementById('targetface-container');
    container.innerHTML = '';

    if (targetfaces.length === 0) {
        container.innerHTML = '<p>Aucun blason disponible pour cette catégorie</p>';
        return;
    }

    // Afficher un sélecteur par départ
    registrationData.sessions.forEach(session => {
        const sessionDiv = document.createElement('div');
        sessionDiv.className = 'targetface-session';

        const label = document.createElement('label');
        label.textContent = `Départ ${session}`;

        const select = document.createElement('select');
        select.id = `targetface-${session}`;
        select.className = 'form-control';
        select.required = true;

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = '-- Choisir un blason --';
        select.appendChild(defaultOption);

        targetfaces.forEach(tf => {
            const option = document.createElement('option');
            option.value = tf.id;
            option.textContent = tf.name;
            if (tf.default === 1) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        sessionDiv.appendChild(label);
        sessionDiv.appendChild(select);
        container.appendChild(sessionDiv);
    });
}

function showSummary() {
    let sessionsText = registrationData.sessions.map(s => {
        const select = document.getElementById(`targetface-${s}`);
        const tfName = select.options[select.selectedIndex].text;
        return `<li>Départ ${s} - ${tfName}</li>`;
    }).join('');

    document.getElementById('summary-content').innerHTML = `
        <p><strong>Licence :</strong> ${registrationData.license}</p>
        <p><strong>Nom :</strong> ${formatProperName(registrationData.name)}</p>
        <p><strong>Prénom :</strong> ${formatProperName(registrationData.firstname)}</p>
        <p><strong>Email :</strong> ${registrationData.email}</p>
        <p><strong>Club :</strong> ${registrationData.club}</p>
        <p><strong>Division :</strong> ${registrationData.division}</p>
        <p><strong>Catégorie :</strong> ${registrationData.ageclass}</p>
        <p><strong>Départs et blasons :</strong></p>
        <ul>${sessionsText}</ul>
    `;
}

function submitRegistration() {
    showLoading();

    const formData = new FormData();
    formData.append('action', 'submit_registration');
    formData.append('tournament_id', TOURNAMENT_ID);
    formData.append('data', JSON.stringify(registrationData));

    fetch('process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showSuccess('Inscription réussie ! Vous allez recevoir une confirmation par email.');
            // Réinitialiser le formulaire après 3 secondes
            setTimeout(() => {
                location.reload();
            }, 30000);
        } else {
            showError(data.error || "Erreur lors de l'inscription");
        }
    })
    .catch(error => {
        hideLoading();
        showError('Erreur réseau : ' + error.message);
    });
}
