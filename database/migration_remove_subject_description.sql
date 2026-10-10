-- Eenmalige migratie voor bestaande installaties. Maak vooraf een databasebackup.
-- Verwijdert uitsluitend de ongebruikte beschrijving van vakken.
ALTER TABLE subjects DROP COLUMN description;
