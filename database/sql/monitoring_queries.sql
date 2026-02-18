-- Requêtes SQL utiles pour monitorer le pipeline CDR TMP → DETAIL

-- ============================================================
-- 1. AUDIT ET MONITORING
-- ============================================================

-- Vue d'ensemble des derniers traitements (dernières 24h)
SELECT 
    SOURCE_DIR,
    FILE_NAME,
    STATUS,
    ROWS_CSV,
    SUBSTR(MESSAGE, 1, 100) as MESSAGE_SHORT,
    TO_CHAR(LOAD_TS, 'YYYY-MM-DD HH24:MI:SS') as LOAD_TIME
FROM LOAD_AUDIT
WHERE LOAD_TS >= SYSDATE - 1
ORDER BY LOAD_TS DESC;

-- Statistiques par statut (aujourd'hui)
SELECT 
    STATUS,
    COUNT(*) as FILE_COUNT,
    SUM(ROWS_CSV) as TOTAL_ROWS
FROM LOAD_AUDIT
WHERE TRUNC(LOAD_TS) = TRUNC(SYSDATE)
GROUP BY STATUS
ORDER BY STATUS;

-- Taux de succès par source (dernière semaine)
SELECT 
    SOURCE_DIR,
    COUNT(*) as TOTAL_FILES,
    SUM(CASE WHEN STATUS = 'SUCCESS' THEN 1 ELSE 0 END) as SUCCESS_COUNT,
    ROUND(SUM(CASE WHEN STATUS = 'SUCCESS' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as SUCCESS_RATE_PCT
FROM LOAD_AUDIT
WHERE LOAD_TS >= SYSDATE - 7
GROUP BY SOURCE_DIR
ORDER BY SOURCE_DIR;

-- Fichiers en erreur avec détail
SELECT 
    FILE_NAME,
    SOURCE_DIR,
    TO_CHAR(LOAD_TS, 'YYYY-MM-DD HH24:MI:SS') as ERROR_TIME,
    MESSAGE
FROM LOAD_AUDIT
WHERE STATUS = 'ERROR'
ORDER BY LOAD_TS DESC
FETCH FIRST 20 ROWS ONLY;

-- Taux de rejet DETAIL par fichier (dernière semaine)
SELECT 
    FILE_NAME,
    TO_CHAR(LOAD_TS, 'YYYY-MM-DD HH24:MI:SS') as LOAD_TIME,
    TO_NUMBER(REGEXP_SUBSTR(MESSAGE, 'TMP:([0-9]+)', 1, 1, NULL, 1)) as TMP_ROWS,
    TO_NUMBER(REGEXP_SUBSTR(MESSAGE, 'DETAIL:([0-9]+)', 1, 1, NULL, 1)) as DETAIL_ROWS,
    TO_NUMBER(REGEXP_SUBSTR(MESSAGE, 'REJECTED:([0-9]+)', 1, 1, NULL, 1)) as REJECTED_ROWS,
    ROUND(
        TO_NUMBER(REGEXP_SUBSTR(MESSAGE, 'REJECTED:([0-9]+)', 1, 1, NULL, 1)) * 100.0 / 
        NULLIF(TO_NUMBER(REGEXP_SUBSTR(MESSAGE, 'TMP:([0-9]+)', 1, 1, NULL, 1)), 0),
        2
    ) as REJECT_RATE_PCT
FROM LOAD_AUDIT
WHERE STATUS = 'SUCCESS'
  AND LOAD_TS >= SYSDATE - 7
ORDER BY REJECT_RATE_PCT DESC NULLS LAST
FETCH FIRST 20 ROWS ONLY;

-- ============================================================
-- 2. VÉRIFICATION TABLES TMP
-- ============================================================

-- Comptage des lignes TMP (ne devrait être vide si cleanup activé)
SELECT 
    'RA_T_TMP_OCC' as TABLE_NAME,
    COUNT(*) as ROW_COUNT,
    COUNT(DISTINCT SOURCE_FILE) as FILE_COUNT
FROM RA_T_TMP_OCC
UNION ALL
SELECT 
    'RA_T_TMP_MMG',
    COUNT(*),
    COUNT(DISTINCT SOURCE_FILE)
FROM RA_T_TMP_MMG;

-- Fichiers restants dans TMP (orphelins ou en cours)
SELECT 
    'OCC' as SOURCE_TYPE,
    SOURCE_FILE,
    COUNT(*) as ROW_COUNT,
    MIN(LOAD_TS) as FIRST_LOAD,
    MAX(LOAD_TS) as LAST_LOAD
FROM RA_T_TMP_OCC
GROUP BY SOURCE_FILE
UNION ALL
SELECT 
    'MMG',
    SOURCE_FILE,
    COUNT(*),
    MIN(LOAD_TS),
    MAX(LOAD_TS)
FROM RA_T_TMP_MMG
GROUP BY SOURCE_FILE
ORDER BY LAST_LOAD DESC;

-- ============================================================
-- 3. ANALYSE DONNÉES DETAIL
-- ============================================================

-- Volume par jour (dernières 30 jours)
SELECT 
    TRUNC(START_DATE) as DATE_JOUR,
    COUNT(*) as TOTAL_EVENTS,
    SUM(DATA_VOLUME) as TOTAL_DATA_MB,
    ROUND(AVG(CHARGE_AMOUNT), 2) as AVG_CHARGE
FROM RA_T_OCC_CDR_DETAIL
WHERE START_DATE >= TRUNC(SYSDATE) - 30
GROUP BY TRUNC(START_DATE)
ORDER BY DATE_JOUR DESC;

-- Top 10 MSISDN par volume de données
SELECT 
    A_MSISDN,
    COUNT(*) as EVENT_COUNT,
    ROUND(SUM(DATA_VOLUME), 2) as TOTAL_DATA_MB,
    ROUND(SUM(CHARGE_AMOUNT), 2) as TOTAL_CHARGE
FROM RA_T_OCC_CDR_DETAIL
WHERE START_DATE >= TRUNC(SYSDATE) - 7
GROUP BY A_MSISDN
ORDER BY TOTAL_DATA_MB DESC
FETCH FIRST 10 ROWS ONLY;

-- Distribution par type d'événement (aujourd'hui)
SELECT 
    EVENT_TYPE,
    CALL_TYPE,
    COUNT(*) as EVENT_COUNT,
    ROUND(SUM(DATA_VOLUME), 2) as TOTAL_DATA_MB
FROM RA_T_OCC_CDR_DETAIL
WHERE TRUNC(START_DATE) = TRUNC(SYSDATE)
GROUP BY EVENT_TYPE, CALL_TYPE
ORDER BY EVENT_COUNT DESC;

-- Distribution horaire (aujourd'hui)
SELECT 
    START_HOUR,
    COUNT(*) as EVENT_COUNT,
    ROUND(AVG(DATA_VOLUME), 2) as AVG_DATA_MB
FROM RA_T_OCC_CDR_DETAIL
WHERE TRUNC(START_DATE) = TRUNC(SYSDATE)
GROUP BY START_HOUR
ORDER BY START_HOUR;

-- ============================================================
-- 4. QUALITÉ DES DONNÉES
-- ============================================================

-- Lignes avec valeurs NULL dans colonnes importantes
SELECT 
    'B_MSISDN' as COLUMN_NAME,
    COUNT(*) as NULL_COUNT,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL WHERE START_DATE >= TRUNC(SYSDATE) - 1), 2) as NULL_PCT
FROM RA_T_OCC_CDR_DETAIL
WHERE B_MSISDN IS NULL
  AND START_DATE >= TRUNC(SYSDATE) - 1
UNION ALL
SELECT 
    'EVENT_COUNT',
    COUNT(*),
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL WHERE START_DATE >= TRUNC(SYSDATE) - 1), 2)
FROM RA_T_OCC_CDR_DETAIL
WHERE EVENT_COUNT IS NULL
  AND START_DATE >= TRUNC(SYSDATE) - 1
UNION ALL
SELECT 
    'DATA_VOLUME',
    COUNT(*),
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL WHERE START_DATE >= TRUNC(SYSDATE) - 1), 2)
FROM RA_T_OCC_CDR_DETAIL
WHERE DATA_VOLUME IS NULL
  AND START_DATE >= TRUNC(SYSDATE) - 1;

-- Vérification doublons (ne devrait rien retourner)
SELECT 
    CHARGING_ID,
    COUNT(*) as DUPLICATE_COUNT
FROM RA_T_OCC_CDR_DETAIL
GROUP BY CHARGING_ID
HAVING COUNT(*) > 1
ORDER BY DUPLICATE_COUNT DESC;

-- MSISDN invalides (format suspect)
SELECT 
    A_MSISDN,
    COUNT(*) as EVENT_COUNT
FROM RA_T_OCC_CDR_DETAIL
WHERE START_DATE >= TRUNC(SYSDATE) - 1
  AND (
    LENGTH(A_MSISDN) < 8 
    OR NOT REGEXP_LIKE(A_MSISDN, '^[0-9+]+$')
  )
GROUP BY A_MSISDN
ORDER BY EVENT_COUNT DESC;

-- ============================================================
-- 5. PERFORMANCE ET VOLUMÉTRIE
-- ============================================================

-- Taille des tables
SELECT 
    segment_name as TABLE_NAME,
    ROUND(bytes / 1024 / 1024, 2) as SIZE_MB,
    ROUND(bytes / 1024 / 1024 / 1024, 2) as SIZE_GB
FROM user_segments
WHERE segment_name IN ('RA_T_OCC_CDR_DETAIL', 'RA_T_TMP_OCC', 'RA_T_TMP_MMG')
ORDER BY bytes DESC;

-- Index et leur état
SELECT 
    index_name,
    table_name,
    uniqueness,
    status,
    num_rows,
    last_analyzed
FROM user_indexes
WHERE table_name IN ('RA_T_OCC_CDR_DETAIL', 'RA_T_TMP_OCC')
ORDER BY table_name, index_name;

-- Statistiques des tables DETAIL
SELECT 
    table_name,
    num_rows,
    blocks,
    avg_row_len,
    TO_CHAR(last_analyzed, 'YYYY-MM-DD HH24:MI:SS') as last_analyzed
FROM user_tables
WHERE table_name LIKE '%CDR_DETAIL'
ORDER BY table_name;

-- ============================================================
-- 6. MAINTENANCE
-- ============================================================

-- Nettoyer TMP manuellement (si stratégie = never)
-- ATTENTION: Vérifier d'abord qu'il n'y a pas de traitement en cours
/*
DELETE FROM RA_T_TMP_OCC 
WHERE SOURCE_FILE IN (
    SELECT FILE_NAME FROM LOAD_AUDIT WHERE STATUS = 'SUCCESS'
);

DELETE FROM RA_T_TMP_MMG 
WHERE SOURCE_FILE IN (
    SELECT FILE_NAME FROM LOAD_AUDIT WHERE STATUS = 'SUCCESS'
);

COMMIT;
*/

-- Supprimer fichiers orphelins dans TMP (>24h sans SUCCESS)
/*
DELETE FROM RA_T_TMP_OCC
WHERE SOURCE_FILE NOT IN (
    SELECT FILE_NAME FROM LOAD_AUDIT 
    WHERE STATUS = 'SUCCESS' 
      AND LOAD_TS >= SYSDATE - 1
)
AND LOAD_TS < SYSDATE - 1;

COMMIT;
*/

-- Archiver l'audit ancien (>90 jours)
/*
DELETE FROM LOAD_AUDIT
WHERE LOAD_TS < SYSDATE - 90;

COMMIT;
*/

-- Rafraîchir les statistiques (à faire régulièrement)
/*
BEGIN
    DBMS_STATS.GATHER_TABLE_STATS(
        ownname => USER,
        tabname => 'RA_T_OCC_CDR_DETAIL',
        estimate_percent => DBMS_STATS.AUTO_SAMPLE_SIZE,
        cascade => TRUE
    );
END;
/
*/

-- ============================================================
-- 7. DASHBOARD EN TEMPS RÉEL
-- ============================================================

-- Vue consolidée pour dashboard
CREATE OR REPLACE VIEW V_CDR_DASHBOARD AS
SELECT 
    -- Statistiques globales (dernières 24h)
    (SELECT COUNT(*) FROM LOAD_AUDIT WHERE LOAD_TS >= SYSDATE - 1) as FILES_24H,
    (SELECT COUNT(*) FROM LOAD_AUDIT WHERE STATUS = 'SUCCESS' AND LOAD_TS >= SYSDATE - 1) as FILES_SUCCESS_24H,
    (SELECT COUNT(*) FROM LOAD_AUDIT WHERE STATUS = 'ERROR' AND LOAD_TS >= SYSDATE - 1) as FILES_ERROR_24H,
    (SELECT SUM(ROWS_CSV) FROM LOAD_AUDIT WHERE STATUS = 'SUCCESS' AND LOAD_TS >= SYSDATE - 1) as ROWS_PROCESSED_24H,
    -- Tables TMP
    (SELECT COUNT(*) FROM RA_T_TMP_OCC) as TMP_OCC_ROWS,
    (SELECT COUNT(*) FROM RA_T_TMP_MMG) as TMP_MMG_ROWS,
    -- Tables DETAIL
    (SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL WHERE START_DATE >= TRUNC(SYSDATE)) as DETAIL_OCC_TODAY,
    (SELECT COUNT(*) FROM RA_T_OCC_CDR_DETAIL) as DETAIL_OCC_TOTAL
FROM DUAL;

-- Utiliser la vue
SELECT * FROM V_CDR_DASHBOARD;
