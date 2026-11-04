-- Bootstrap permissions and role mappings

INSERT INTO permissions (name) VALUES
    ('deduct_marks'),
    ('view_students'),
    ('manage_students'),
    ('view_discipline_history'),
    ('manage_users'),
    ('view_dashboard'),
    ('manage_subscriptions'),
    ('upload_files'),
    ('generate_reports'),
    ('assess_risk');

INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
    FROM roles r
    CROSS JOIN permissions p
    WHERE LOWER(r.name) IN ('admin', 'headteacher', 'it');

INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id
    FROM roles r
    CROSS JOIN permissions p
    WHERE LOWER(r.name) IN ('teacher', 'deputy headteacher discipline')
      AND LOWER(p.name) IN (
          'deduct_marks', 'view_students',
          'view_discipline_history', 'view_dashboard',
          'generate_reports', 'assess_risk'
      );
