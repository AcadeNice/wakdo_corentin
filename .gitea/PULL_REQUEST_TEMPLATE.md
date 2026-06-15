<!--
Modele de PR Wakdo (Forgejo). Conventions BYAN : Merise Agile + TDD + 64 mantras.
Remplis les sections, coche ce qui s'applique, supprime ce qui ne sert pas.
-->

## Description

<!-- Quoi et pourquoi. Lier la decision / le journal / l'issue si pertinent. -->

## Type

- [ ] feat
- [ ] fix
- [ ] docs
- [ ] refactor
- [ ] test
- [ ] chore

## Checklist conventions BYAN

- [ ] Pas d'emoji dans le code, les commits ou les specs (mantra IA-23)
- [ ] Commits au format `type: description` en anglais, sans trailer `Co-Authored-By`
- [ ] Claims techniques sources si applicable (protocole fact-check)
- [ ] Docs Merise / dictionnaire a jour si le modele de donnees change
- [ ] Tests ajoutes et passants si du code est touche (unit > integration > e2e)

## Checklist securite (security-by-design)

<!-- Cocher ce qui s'applique ; voir SECURITY.md et PROJECT_CONTEXT section 19. -->

- [ ] Aucun secret commite (CI gitleaks verte) ; `.env` reste gitignore
- [ ] Entrees utilisateur validees ; requetes SQL en prepared statements (anti-injection)
- [ ] Mots de passe / PIN en argon2id ; pas de donnee sensible en clair ni dans les logs
- [ ] Sorties HTML echappees (anti-XSS) ; CSRF gere sur les formulaires d'etat
- [ ] Permissions RBAC verifiees cote serveur pour toute action sensible
- [ ] Impact RGPD evalue si nouvelles donnees personnelles (retention, droit a l'effacement)

## Bloc RNCP impacte

<!-- ex : Bloc 2 Cr 3.b (modelisation), Bloc 1 (accessibilite), Bloc 5 (infra/CI)... -->

## Base de la PR

- [ ] La base de cette PR est `dev` (et non `main`)
