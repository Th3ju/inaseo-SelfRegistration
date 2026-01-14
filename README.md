# Module d'Auto-inscription IANSEO v1.0

## Installation à la racine

Ce module est conçu pour être installé **à la racine** de votre serveur web, au même niveau qu'IANSEO.

### Structure attendue

```
/var/www/html/
├── Common/               (IANSEO - dossier existant)
├── Modules/              (IANSEO - dossier existant)
├── Qualification/        (IANSEO - dossier existant)
├── index.php             (IANSEO - fichier existant)
└── SelfRegistration/     ← CE MODULE ICI
    ├── index.php
    ├── process.php
    ├── config.txt
    ├── css/
    │   └── style.css
    ├── js/
    │   └── script.js
    └── README.md
```

### Étapes d'installation

1. **Décompressez** l'archive à la racine de votre serveur web :
   ```bash
   cd /var/www/html/
   unzip SelfRegistration_Module_IANSEO.zip
   ```

2. **Vérifiez** les permissions :
   ```bash
   chmod 755 SelfRegistration/
   chmod 644 SelfRegistration/*.php
   chmod 644 SelfRegistration/config.txt
   ```

3. **Configurez** les tokens dans `config.txt` :
   ```
   124|MONTOKENSECRET
   125|AUTRETOKEN
   ```

4. **Testez** l'installation :
   ```
   https://votre-domaine.fr/SelfRegistration/index.php?tournament=124&token=MONTOKENSECRET
   ```

### Chemins de configuration

Le module détecte automatiquement l'installation IANSEO avec les chemins suivants :

1. **Chemin principal** : `/var/www/html/Common/config.inc.php`
2. **Chemin alternatif** : `/var/www/html/ianseo/Common/config.inc.php`

Si votre installation est différente, modifiez les lignes suivantes dans `index.php` et `process.php` :

```php
$ianseo_root = dirname(__DIR__); // Modifier ici si nécessaire
$config_path = $ianseo_root . '/Common/config.inc.php';
```

## Mode Debug

Le mode debug est **ACTIVÉ par défaut** pour faciliter les tests.

### Informations affichées en mode debug :
- Chemins de configuration détectés
- Requêtes SQL exécutées
- Données transmises entre client/serveur
- Traces d'erreur complètes

### Désactiver en production

Dans `index.php` et `process.php`, changez :
```php
define('DEBUG_MODE', false);
```

## Utilisation

### URL d'accès
```
https://votre-domaine.fr/SelfRegistration/index.php?tournament=TOURNAMENT_ID&token=VOTRE_TOKEN
```

### Processus d'inscription

1. **Étape 1** : L'archer entre son numéro de licence
2. **Étape 2** : Sélection division, catégorie et départs
3. **Étape 3** : Récapitulatif et validation

## Fonctionnalités

- ✅ Recherche par numéro de licence dans LookUpEntries
- ✅ Vérification des doublons
- ✅ Calcul automatique de la catégorie d'âge
- ✅ Sélection des types de blasons selon Division+Classe
- ✅ Multi-départs (une ligne Entries par départ)
- ✅ EnIndClEvent = 1 pour le premier départ, 0 pour les autres
- ✅ Interface responsive Bootstrap 5
- ✅ Mode debug complet

## Règles métier

- **Doublon** : Un archer ne peut s'inscrire qu'une fois
- **Catégories** : Calculées selon âge et sexe
- **Blasons** : Proposés automatiquement
- **Multi-départs** : Création d'une ligne Entries par départ sélectionné

## Tables modifiées

### Entries
Champs insérés : `EnTournament`, `EnCode`, `EnIocCode`, `EnName`, `EnFirstName`, `EnSex`, `EnDob`, `EnDivision`, `EnClass`, `EnAgeClass`, `EnCountry`, `EnTargetFace`, `EnStatus`, `EnAthlete`, `EnClassified`, `EnIndClEvent`, etc.

### Qualifications
Association avec les sessions : `QuId`, `QuSession`

## Dépannage

### Erreur "Config IANSEO introuvable"

Vérifiez que le dossier `Common/` existe au même niveau que `SelfRegistration/` :
```bash
ls -la /var/www/html/
# Vous devez voir : Common/, Modules/, SelfRegistration/
```

### Erreur de connexion base de données

Vérifiez le fichier `/Common/config.inc.php` et les identifiants MySQL.

### Erreur "Token invalide"

Vérifiez que votre `config.txt` contient la bonne ligne :
```
TOURNAMENT_ID|TOKEN
```

## Support

Pour toute question, contactez l'administrateur IANSEO.

## Version

**v1.0** - Janvier 2026
- Installation à la racine
- Mode debug amélioré
- Détection automatique des chemins IANSEO
