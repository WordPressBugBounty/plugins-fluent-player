-- ---------------------------------------------------------------------------
-- LEVENSHTEIN() stored FUNCTION for MySQL / MariaDB
-- ---------------------------------------------------------------------------
--
-- Required by the query builder's fuzzy "similar to" helpers:
--
--     DB::table('users')->whereSimilar('name', 'bill', 2)->get();
--     User::similar('collap')->get();   // Searchable::scopeSimilar()
--
-- which compile to:  levenshtein(lower(<column>), lower(?)) <= ?
--
-- This is a stored FUNCTION (not a PROCEDURE) on purpose: it RETURNS a value
-- and can therefore be used inline inside a WHERE/SELECT expression. A stored
-- procedure (CALL ...) cannot be used that way.
--
-- MySQL ships SOUNDEX() natively, so phonetic ("sounds like") queries need
-- nothing extra. SQLite registers BOTH soundex() and levenshtein() as
-- PHP-backed UDFs automatically (see WPDBConnection::registerSqliteFunctions),
-- so this file is only needed for MySQL / MariaDB.
--
-- Install once per database:
--
--     mysql -u <user> -p <database> < database/levenshtein.sql
--
-- Returns the number of single-character edits (insertions, deletions,
-- substitutions) needed to turn s1 into s2. Case-insensitivity is handled by
-- the query (lower(...)); pass already-lowercased values if calling directly.
-- ---------------------------------------------------------------------------

DROP FUNCTION IF EXISTS LEVENSHTEIN;

DELIMITER $$

CREATE FUNCTION LEVENSHTEIN(s1 VARCHAR(255) CHARACTER SET utf8mb4, s2 VARCHAR(255) CHARACTER SET utf8mb4)
    RETURNS INT
    DETERMINISTIC
    NO SQL
    BEGIN
        DECLARE s1_len, s2_len, i, j, c, c_temp, cost INT;
        DECLARE s1_char CHAR CHARACTER SET utf8mb4;
        DECLARE cv0, cv1 VARBINARY(256);

        SET s1_len = CHAR_LENGTH(s1),
            s2_len = CHAR_LENGTH(s2),
            cv1 = 0x00,
            j = 1,
            i = 1,
            c = 0;

        IF s1 = s2 THEN
            RETURN 0;
        ELSEIF s1_len = 0 THEN
            RETURN s2_len;
        ELSEIF s2_len = 0 THEN
            RETURN s1_len;
        END IF;

        WHILE j <= s2_len DO
            SET cv1 = CONCAT(cv1, UNHEX(HEX(j))), j = j + 1;
        END WHILE;

        WHILE i <= s1_len DO
            SET s1_char = SUBSTRING(s1, i, 1),
                c = i,
                cv0 = UNHEX(HEX(i)),
                j = 1;

            WHILE j <= s2_len DO
                SET c = c + 1;
                SET cost = IF(s1_char = SUBSTRING(s2, j, 1), 0, 1);

                SET c_temp = CONV(HEX(SUBSTRING(cv1, j, 1)), 16, 10) + cost;
                IF c > c_temp THEN
                    SET c = c_temp;
                END IF;

                SET c_temp = CONV(HEX(SUBSTRING(cv1, j + 1, 1)), 16, 10) + 1;
                IF c > c_temp THEN
                    SET c = c_temp;
                END IF;

                SET cv0 = CONCAT(cv0, UNHEX(HEX(c))), j = j + 1;
            END WHILE;

            SET cv1 = cv0, i = i + 1;
        END WHILE;

        RETURN c;
    END$$

DELIMITER ;
