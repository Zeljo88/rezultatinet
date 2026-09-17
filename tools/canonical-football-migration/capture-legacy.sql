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
    '|', 'FK', constraint_name, table_name, column_name,
    referenced_table_name, referenced_column_name, ordinal_position,
    update_rule, delete_rule
)
FROM information_schema.key_column_usage
JOIN information_schema.referential_constraints
  USING (constraint_schema, constraint_name, table_name)
WHERE constraint_schema = DATABASE()
  AND table_name IN ('leagues', 'teams', 'fixtures', 'fixture_scores')
ORDER BY table_name, constraint_name, ordinal_position;
