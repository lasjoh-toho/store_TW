<?php
/*
 * auth.php – Benutzer für store.php
 *
 * Diese Datei als auth.php neben store.php speichern.
 * Statt Klartext-Passwörtern stehen hier nur Hashes. Einen Hash erzeugt
 * man mit  https://deine-domain/pfad/store.php?hash  oder  php store.php hash
 *
 * Achtung: Hashes immer in 'einfachen' Anführungszeichen eintragen,
 * sonst macht PHP aus den $-Zeichen Variablen.
 */
$USERS = [
    'jo' => '$2y$10$ERSETZEN.durch.den.Hash.aus.store.php?hash..............',
];
