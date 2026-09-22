-- =============================================================================
-- Wakdo — Seed 0006 : boisson de menu = variante 50 cl automatique en Maxi
-- =============================================================================
-- Purpose : cabler la regle metier "boisson Maxi" sur les donnees seedees, sans
--           toucher au code. En menu Maxi, la boisson fontaine doit passer en
--           grande (50 cl), comme l'accompagnement passe en Grande Frite.
--
--           Mecanique reutilisee : product.maxi_variant_product_id (schema 0006),
--           deja exploite par OrderRepository::resolveSelections (substitution de
--           toute selection de menu au format 'maxi', sans garde sur le slot_type).
--           Il suffit donc de POINTER chaque soda fontaine 30 cl vers sa variante
--           50 cl (creee par le seed 0005) : aucune ligne de code serveur a ecrire.
--           Le decrement de stock (consumption) frappera la 50 cl, et le snapshot
--           de libelle reflechira "<soda> 50cl".
--
-- Perimetre : seules les boissons fontaine ont une variante 50 cl (Coca Cola, Coca
-- Sans Sucres, Fanta Orange, Ice Tea Peche, Ice Tea Citron). Les boissons en
-- bouteille (Eau, Jus d'Orange, Jus de Pommes Bio) n'ont pas de variante : elles
-- restent en taille standard meme en Maxi (degradation gracieuse, modele fast-food
-- usuel). Le surcout Maxi est porte par le menu (price_maxi_cents), pas par la
-- boisson : aucune incidence de prix sur ces bouteilles.
--
-- Phase   : depend du schema 0006 (maxi_variant_product_id) ET du seed 0005 (les
--           variantes 50 cl doivent exister). Joue donc APRES 0005 (ordre
--           lexicographique du runner db/seed.sh).
--
-- Conventions:
--   - Aucun id en dur : la cible est resolue structurellement (la variante 50 cl
--     est la ligne dont base_product_id pointe la base et size_cl = 50).
--   - IDEMPOTENT : UPDATE ... JOIN convergent (repositionne la meme valeur a chaque
--     execution). MariaDB autorise le self-join en UPDATE multi-tables (l'erreur
--     1093 ne vise que les sous-requetes sur la table cible, pas les JOIN).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Lier chaque boisson de base (30 cl, base_product_id NULL) a sa variante 50 cl.
-- La jointure ne matche que les produits ayant une variante de taille 50 cl :
-- structurellement, les seules boissons fontaine. Les accompagnements (frites,
-- deja relies par 0004) ne sont pas des variantes de taille -> non touches. Les
-- bouteilles sans variante 50 cl ne matchent pas -> maxi_variant_product_id reste
-- NULL.
-- -----------------------------------------------------------------------------
UPDATE product AS base
JOIN product AS variant
    ON variant.base_product_id = base.id
   AND variant.size_cl = 50
SET base.maxi_variant_product_id = variant.id
WHERE base.base_product_id IS NULL;
