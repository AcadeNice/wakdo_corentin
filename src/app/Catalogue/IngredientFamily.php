<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Source unique de la classification des ingredients en familles (migration 0017).
 * Le selecteur d'ingredients du formulaire produit proposait les 50 ingredients a
 * plat, sans lien avec la categorie du produit compose (ex. "Brownie" propose en
 * composant un burger) : cette classe porte l'ordre canonique + les libelles
 * francais des dix familles, reutilisee par `IngredientRepository`,
 * `CategoryIngredientFamilyRepository`, `ProductController` et
 * `IngredientController` -- une seule liste, jamais dupliquee.
 *
 * `ingredient.family` reste NULLABLE (migration 0017) : NULL = non classe = visible
 * dans toutes les categories (degradation sure, jamais un ingredient qui disparait
 * silencieusement d'un formulaire faute d'avoir ete classe).
 *
 * Repond aussi a la limite de modelisation documentee dans
 * docs/adr/0015-allergenes-calcules-par-produit.md (consequences, "Gobelet" porte
 * comme ingredient de recette alors que ce n'est pas un aliment) : plutot qu'un
 * drapeau `is_food` dedie (qui ferait doublon avec cette classification), la
 * famille `contenant` isole ce cas ; `isFood()` derive la reponse depuis la
 * famille au lieu d'une colonne redondante.
 */
final class IngredientFamily
{
    public const PAIN = 'pain';
    public const VIANDE = 'viande';
    public const FROMAGE = 'fromage';
    public const LEGUME = 'legume';
    public const SAUCE = 'sauce';
    public const FECULENT = 'feculent';
    public const DOSE_BOISSON = 'dose_boisson';
    public const CONTENANT = 'contenant';
    public const DESSERT = 'dessert';
    public const DOSETTE = 'dosette';

    /**
     * Ordre canonique (contrat fige) : c'est aussi l'ordre d'affichage des
     * options du selecteur "Famille" du formulaire ingredient.
     *
     * @var list<string>
     */
    public const ORDER = [
        self::PAIN,
        self::VIANDE,
        self::FROMAGE,
        self::LEGUME,
        self::SAUCE,
        self::FECULENT,
        self::DOSE_BOISSON,
        self::CONTENANT,
        self::DESSERT,
        self::DOSETTE,
    ];

    /**
     * Libelles affiches en francais, dans l'ordre canonique (l'insertion PHP
     * preserve l'ordre des cles -- pas besoin de retrier ailleurs).
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::PAIN         => 'Pain',
        self::VIANDE       => 'Viande',
        self::FROMAGE      => 'Fromage',
        self::LEGUME       => 'Légume',
        self::SAUCE        => 'Sauce',
        self::FECULENT     => 'Féculent',
        self::DOSE_BOISSON => 'Dose boisson',
        self::CONTENANT    => 'Contenant',
        self::DESSERT      => 'Dessert',
        self::DOSETTE      => 'Dosette',
    ];

    /**
     * Slug -> libelle francais, dans l'ordre canonique. Source unique consommee
     * par ProductController (`ingredientFamilies`) et par le select du
     * formulaire ingredient.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return self::ORDER;
    }

    /**
     * Le slug appartient-il a la liste canonique ? Utilise par la validation
     * serveur du formulaire ingredient (une valeur hors liste est refusee, une
     * valeur vide -- non classe -- est acceptee ailleurs, pas ici).
     */
    public static function isValid(string $family): bool
    {
        return isset(self::LABELS[$family]);
    }

    /**
     * Un ingredient de cette famille est-il un ALIMENT (par opposition a un
     * materiau au contact des denrees, reglement 1935/2004) ? Seule la famille
     * `contenant` (le cas Gobelet) repond non ; un ingredient NON CLASSE (null)
     * est traite comme un aliment par defaut -- un ingredient qu'on n'a pas
     * encore range n'a aucune raison d'etre presume non-alimentaire, et c'est la
     * meme degradation sure que le filtrage par categorie (non classe = visible
     * partout). Derive de la famille plutot que porte par une colonne dediee
     * (`is_food` aurait fait doublon, voir docs/adr/0015).
     */
    public static function isFood(?string $family): bool
    {
        return $family !== self::CONTENANT;
    }
}
