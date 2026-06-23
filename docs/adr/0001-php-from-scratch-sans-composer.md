# ADR-0001 — PHP from scratch, sans framework ni Composer

- Statut : Accepte
- Date : 2026-04-23

## Contexte
Certification RNCP (Titre Developpeur Web, option DevOps). L'objectif pedagogique est
de demontrer la maitrise des fondamentaux (routage, PDO, sessions, securite) plutot que
la configuration d'un framework. Options : Symfony/Laravel ; micro-framework (Slim) ;
from scratch.

## Decision
Application PHP 8.3 ecrite **from scratch** : routeur, autoloader PSR-4 manuel
(`spl_autoload_register`), couche `Database` sur PDO, le tout **sans Composer**. Les
outils de dev (PHPUnit, PHPStan) sont utilises via leurs **`.phar` autonomes**.

## Consequences
- (+) Chaque mecanisme (routage, auth, RBAC, requetes preparees) est explicite et
  defendable a l'oral ; pas de magie de framework.
- (+) Surface de dependances minimale (moins de supply-chain a auditer).
- (-) Du code d'infrastructure a ecrire et tester soi-meme (Core, Auth).
- CI sans Composer : les `.phar` (phpunit, phpstan) sont epingles/telecharges.
  Voir `docs/PROJECT_CONTEXT.md` section 6.
