<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Conversion euros <-> centimes pour les formulaires de prix (F40, section "Textes
 * techniques ou en anglais" de defauts-visibles.md : un equipier saisit et lit un
 * prix en euros, jamais en centimes ; la base reste en centimes, seule unite qui
 * evite toute perte d'arrondi en calcul).
 *
 * parseEurosToCents() est la source unique de validation cote serveur (RG-T18) :
 * accepte la virgule ET le point comme separateur decimal (« 1,90 » ou « 1.90 »,
 * comme demande), 0 ou 2 decimales, jusqu'a 7 chiffres avant la virgule (borne
 * haute alignee sur le UNSIGNED INT des colonnes price_cents en base). Toute autre
 * forme (texte, plus de 2 decimales, negatif, vide) est rejetee (null).
 *
 * Classe non instanciable (methodes statiques uniquement) : pas d'etat, pure
 * fonction de conversion.
 */
final class Money
{
    private const MAX_CENTS = 4294967295;

    private function __construct()
    {
    }

    /**
     * Parse une saisie utilisateur en euros vers un montant en centimes.
     * Renvoie null si la saisie n'est pas un montant valide (le controleur pose
     * alors l'erreur de validation ; il ne devine jamais une valeur par defaut).
     */
    public static function parseEurosToCents(string $raw): ?int
    {
        $normalized = str_replace(',', '.', trim($raw));
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return ($cents > 0 && $cents <= self::MAX_CENTS) ? $cents : null;
    }

    /**
     * Formate un montant en centimes pour un champ de saisie en euros (repli d'un
     * formulaire d'edition). Virgule francaise, toujours 2 decimales ("1,90"), pas
     * de separateur de milliers (une valeur de champ de formulaire, pas un affichage
     * de liste -- $euros() des vues gere deja l'affichage avec "EUR").
     */
    public static function centsToEuros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }
}
