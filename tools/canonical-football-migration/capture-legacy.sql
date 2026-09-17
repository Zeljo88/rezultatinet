SELECT CONCAT_WS('|', 'COUNT', 'leagues', COUNT(*)) FROM leagues
UNION ALL SELECT CONCAT_WS('|', 'COUNT', 'teams', COUNT(*)) FROM teams
UNION ALL SELECT CONCAT_WS('|', 'COUNT', 'fixtures', COUNT(*)) FROM fixtures
UNION ALL SELECT CONCAT_WS('|', 'COUNT', 'fixture_scores', COUNT(*)) FROM fixture_scores
ORDER BY 1;

CHECKSUM TABLE leagues, teams, fixtures, fixture_scores;

SELECT CONCAT_WS(
    '|', 'COLUMN', table_name, LPAD(ordinal_position, 3, '0'), column_name,
    column_type, is_nullable, COALESCE(column_default, '<NULL>'),
    COALESCE(character_set_name, '<NULL>'), COALESCE(collation_name, '<NULL>'),
    COALESCE(extra, '<NULL>')
)
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('leagues', 'teams', 'fixtures', 'fixture_scores')
ORDER BY table_name, ordinal_position;

SELECT CONCAT_WS(
    '|', 'INDEX', table_name, index_name, LPAD(seq_in_index, 3, '0'),
    column_name, non_unique, COALESCE(sub_part, '<NULL>'), index_type
)
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('leagues', 'teams', 'fixtures', 'fixture_scores')
ORDER BY table_name, index_name, seq_in_index;

SELECT CONCAT_WS(
    '|', 'FK', kcu.constraint_name, kcu.table_name, kcu.column_name,
    kcu.referenced_table_name, kcu.referenced_column_name, kcu.ordinal_position,
    rc.update_rule, rc.delete_rule
)
FROM information_schema.key_column_usage kcu
JOIN information_schema.referential_constraints rc
  ON rc.constraint_schema = kcu.constraint_schema
 AND rc.constraint_name = kcu.constraint_name
 AND rc.table_name = kcu.table_name
WHERE kcu.constraint_schema = DATABASE()
  AND kcu.table_name IN ('leagues', 'teams', 'fixtures', 'fixture_scores')
ORDER BY kcu.table_name, kcu.constraint_name, kcu.ordinal_position;
