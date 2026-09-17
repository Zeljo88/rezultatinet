SELECT CONCAT_WS(
    '|', 'TABLE', table_name, engine, table_collation
)
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'sports', 'providers', 'competitions', 'competition_seasons',
    'participants', 'events', 'event_participants',
    'provider_competition_mappings', 'provider_participant_mappings',
    'provider_event_mappings', 'import_runs', 'identity_quarantines'
  )
ORDER BY table_name;

SELECT CONCAT_WS(
    '|', 'COLUMN', table_name, LPAD(ordinal_position, 3, '0'), column_name,
    column_type, is_nullable, COALESCE(column_default, '<NULL>'),
    COALESCE(character_set_name, '<NULL>'), COALESCE(collation_name, '<NULL>'),
    COALESCE(extra, '<NULL>'), COALESCE(generation_expression, '<NULL>'),
    COALESCE(column_comment, '<NULL>')
)
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN (
    'sports', 'providers', 'competitions', 'competition_seasons',
    'participants', 'events', 'event_participants',
    'provider_competition_mappings', 'provider_participant_mappings',
    'provider_event_mappings', 'import_runs', 'identity_quarantines'
  )
ORDER BY table_name, ordinal_position;

SELECT CONCAT_WS(
    '|', 'INDEX', table_name, index_name, LPAD(seq_in_index, 3, '0'),
    column_name, non_unique, COALESCE(sub_part, '<NULL>'),
    COALESCE(collation, '<NULL>'), index_type
)
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN (
    'sports', 'providers', 'competitions', 'competition_seasons',
    'participants', 'events', 'event_participants',
    'provider_competition_mappings', 'provider_participant_mappings',
    'provider_event_mappings', 'import_runs', 'identity_quarantines'
  )
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
  AND table_name IN (
    'sports', 'providers', 'competitions', 'competition_seasons',
    'participants', 'events', 'event_participants',
    'provider_competition_mappings', 'provider_participant_mappings',
    'provider_event_mappings', 'import_runs', 'identity_quarantines'
  )
ORDER BY table_name, constraint_name, ordinal_position;

SELECT CONCAT_WS(
    '|', 'CHECK', table_name, constraint_name, check_clause
)
FROM information_schema.check_constraints
WHERE constraint_schema = DATABASE()
  AND table_name IN (
    'sports', 'providers', 'competitions', 'competition_seasons',
    'participants', 'events', 'event_participants',
    'provider_competition_mappings', 'provider_participant_mappings',
    'provider_event_mappings', 'import_runs', 'identity_quarantines'
  )
ORDER BY table_name, constraint_name;
