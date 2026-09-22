<?php
/**
 * CSV Bulk Import endpoints
 *
 * POST   /api/v1/landlords/import            import landlords from CSV
 * GET    /api/v1/landlords/import/template   download landlord CSV template
 *
 * POST   /api/v1/properties/import           import properties from CSV
 * GET    /api/v1/properties/import/template  download property CSV template
 *
 * POST   /api/v1/units/import                import units from CSV
 * GET    /api/v1/units/import/template       download unit CSV template
 *
 * POST   /api/v1/tenants/import              import tenants from CSV
 * GET    /api/v1/tenants/import/template     download tenant CSV template
 *
 * All POST endpoints accept multipart/form-data with a single "file" field (CSV).
 * Response: { imported, skipped, errors: [{row, error}] }
 *
 * Idempotent: rows that already exist (by email / id_number) are skipped, not errored.
 */
function registerImportRoutes(Router $router, PDO $db): void
{
    // ── Shared: parse an uploaded CSV into an array of assoc rows ──
    $parseCsv = static function (): array|string {
        $file = $_FILES['file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'No file uploaded or upload error (code ' . ($file['error'] ?? UPLOAD_ERR_NO_FILE) . ').';
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $mime = $file['type'] ?? '';
        if ($ext !== 'csv' && !str_contains($mime, 'csv') && !str_contains($mime, 'text')) {
            return 'File must be a .csv file.';
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) return 'Cannot read the uploaded file.';

        $header = null;
        $rows   = [];
        while (($cols = fgetcsv($handle)) !== false) {
            if ($header === null) {
                // Normalise header: strip UTF-8 BOM, lowercase, trim whitespace
                $header = array_map(
                    fn($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h))),
                    $cols
                );
                continue;
            }
            // Skip entirely blank rows
            if (count(array_filter($cols, fn($v) => trim($v) !== '')) === 0) continue;
            // Pad shorter rows so array_combine never fails
            $rows[] = array_combine($header, array_pad($cols, count($header), ''));
        }
        fclose($handle);

        if (!$header) return 'CSV file appears to be empty.';
        return $rows;
    };

    // ── Shared: emit a CSV template response ─────────────────────
    $csvTemplate = static function (string $filename, array $headers, array $example): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM for Excel
        fputcsv($out, $headers);
        fputcsv($out, $example);
        fclose($out);
        exit;
    };

    // ── Shared: parse a boolean CSV cell (1/0/yes/no/true/false) ─
    $parseBool = static fn(string $v): ?int =>
        $v === '' ? null : (in_array(strtolower(trim($v)), ['1', 'yes', 'true', 'y'], true) ? 1 : 0);

    // ════════════════════════════════════════════════════════════
    // LANDLORDS
    // ════════════════════════════════════════════════════════════

    $router->get('landlords/import/template', function () use ($csvTemplate, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        ($csvTemplate)(
            'landlords_import_template.csv',
            ['name', 'email', 'phone', 'id_number', 'kra_pin',
             'bank_name', 'bank_account', 'bank_branch',
             'mpesa_number', 'commission_rate', 'notes'],
            ['John Doe', 'john@example.com', '0712345678', '12345678',
             'A123456789B', 'Equity Bank', '0123456789', 'Westlands',
             '0712345678', '5', 'Sample landlord']
        );
    });

    $router->post('landlords/import', function () use ($db, $parseCsv) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $rows = $parseCsv();
        if (is_string($rows)) ApiResponse::badRequest($rows);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // row 1 = header
            $name   = trim($row['name']  ?? '');
            $email  = strtolower(trim($row['email'] ?? ''));

            if (!$name || !$email) {
                $errors[] = ['row' => $rowNum, 'error' => 'name and email are required.'];
                $skipped++;
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNum, 'error' => "Invalid email address: $email"];
                $skipped++;
                continue;
            }

            // Skip duplicate emails gracefully
            $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
            $emailCheck->execute([$email]);
            if ($emailCheck->fetchColumn()) {
                $errors[] = ['row' => $rowNum, 'error' => "Email already registered (skipped): $email"];
                $skipped++;
                continue;
            }

            // Check ID number uniqueness when provided
            $idNumber = trim($row['id_number'] ?? '');
            $idHash   = $idNumber ? Encryptor::hash($idNumber) : null;
            if ($idHash) {
                $idCheck = $db->prepare("SELECT id FROM landlords WHERE id_number_hash = ?");
                $idCheck->execute([$idHash]);
                if ($idCheck->fetchColumn()) {
                    $errors[] = ['row' => $rowNum, 'error' => "ID number already registered (skipped): $idNumber"];
                    $skipped++;
                    continue;
                }
            }

            $defaultPass = password_hash('Rums@1234.', PASSWORD_BCRYPT, ['cost' => 10]);

            $db->beginTransaction();
            try {
                $db->prepare(
                    "INSERT INTO users (name, email, phone, password, role) VALUES (?,?,?,?,'landlord')"
                )->execute([
                    $name,
                    $email,
                    trim($row['phone'] ?? '') ?: null,
                    $defaultPass,
                ]);
                $userId = (int)$db->lastInsertId();

                $kra     = trim($row['kra_pin']      ?? '');
                $bankAcc = trim($row['bank_account']  ?? '');
                $mpesa   = trim($row['mpesa_number']  ?? '');
                $rate    = $row['commission_rate'] ?? '';

                $db->prepare(
                    "INSERT INTO landlords
                        (user_id, id_number, id_number_hash, kra_pin,
                         bank_name, bank_account, bank_branch,
                         mpesa_number, commission_rate, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $userId,
                    $idNumber ? Encryptor::encrypt($idNumber) : null,
                    $idHash,
                    $kra     ? Encryptor::encrypt($kra)     : null,
                    trim($row['bank_name']   ?? '') ?: null,
                    $bankAcc ? Encryptor::encrypt($bankAcc) : null,
                    trim($row['bank_branch'] ?? '') ?: null,
                    $mpesa   ? Encryptor::encrypt($mpesa)   : null,
                    is_numeric($rate) ? (float)$rate : 0.0,
                    trim($row['notes'] ?? '') ?: null,
                ]);

                $db->commit();
                $imported++;
            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = ['row' => $rowNum, 'error' => $e->getMessage()];
                $skipped++;
            }
        }

        ApiResponse::ok(
            compact('imported', 'skipped', 'errors'),
            "$imported landlord(s) imported, $skipped skipped."
        );
    });

    // ════════════════════════════════════════════════════════════
    // PROPERTIES
    // ════════════════════════════════════════════════════════════

    $router->get('properties/import/template', function () use ($csvTemplate, $db) {
        ApiAuth::requireScope($db, 'write:properties');
        ($csvTemplate)(
            'properties_import_template.csv',
            ['name', 'property_type', 'address_line1', 'address_line2',
             'address_city', 'address_county', 'address_country',
             'year_built', 'landlord_email', 'description', 'amenities'],
            ['Sunshine Apartments', 'apartment', '123 Main Street', 'Suite 4',
             'Nairobi', 'Nairobi County', 'Kenya',
             '2015', 'john@example.com', 'Modern apartments', 'Pool, Gym, Parking']
        );
    });

    $router->post('properties/import', function () use ($db, $parseCsv) {
        ApiAuth::requireScope($db, 'write:properties');

        $rows = $parseCsv();
        if (is_string($rows)) ApiResponse::badRequest($rows);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        // Cache landlord email → landlord id lookups
        $landlordCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $name   = trim($row['name'] ?? '');
            $type   = strtolower(trim($row['property_type'] ?? $row['type'] ?? ''));

            if (!$name) {
                $errors[] = ['row' => $rowNum, 'error' => 'name is required.'];
                $skipped++;
                continue;
            }
            if (!$type) {
                $errors[] = ['row' => $rowNum, 'error' => 'property_type is required.'];
                $skipped++;
                continue;
            }

            // Resolve landlord by email
            $landlordId    = null;
            $landlordEmail = strtolower(trim($row['landlord_email'] ?? ''));
            if ($landlordEmail) {
                if (!array_key_exists($landlordEmail, $landlordCache)) {
                    $lStmt = $db->prepare(
                        "SELECT l.id FROM landlords l
                         JOIN users u ON u.id = l.user_id
                         WHERE u.email = ? LIMIT 1"
                    );
                    $lStmt->execute([$landlordEmail]);
                    $landlordCache[$landlordEmail] = $lStmt->fetchColumn() ?: null;
                }
                if (!$landlordCache[$landlordEmail]) {
                    $errors[] = ['row' => $rowNum, 'error' => "Landlord not found for email: $landlordEmail"];
                    $skipped++;
                    continue;
                }
                $landlordId = (int)$landlordCache[$landlordEmail];
            }

            $yearBuilt = trim($row['year_built'] ?? '');
            $allowed   = array_filter([
                'name'            => $name,
                'property_type'   => $type,
                'address_line1'   => trim($row['address_line1']   ?? '') ?: null,
                'address_line2'   => trim($row['address_line2']   ?? '') ?: null,
                'address_city'    => trim($row['address_city']    ?? $row['city']    ?? '') ?: null,
                'address_county'  => trim($row['address_county']  ?? $row['county']  ?? '') ?: null,
                'address_country' => trim($row['address_country'] ?? $row['country'] ?? '') ?: null,
                'year_built'      => is_numeric($yearBuilt) ? (int)$yearBuilt : null,
                'landlord_id'     => $landlordId,
                'description'     => trim($row['description'] ?? '') ?: null,
                'amenities'       => trim($row['amenities']   ?? '') ?: null,
                'status'          => trim($row['status'] ?? '') ?: 'active',
            ], fn($v) => $v !== null);

            try {
                $cols   = implode(', ', array_keys($allowed));
                $places = implode(', ', array_fill(0, count($allowed), '?'));
                $db->prepare("INSERT INTO properties ($cols) VALUES ($places)")->execute(array_values($allowed));
                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNum, 'error' => $e->getMessage()];
                $skipped++;
            }
        }

        ApiResponse::ok(
            compact('imported', 'skipped', 'errors'),
            "$imported propert" . ($imported === 1 ? 'y' : 'ies') . " imported, $skipped skipped."
        );
    });

    // ════════════════════════════════════════════════════════════
    // UNITS
    // ════════════════════════════════════════════════════════════

    $router->get('units/import/template', function () use ($csvTemplate, $db) {
        ApiAuth::requireScope($db, 'write:units');
        ($csvTemplate)(
            'units_import_template.csv',
            ['property_name', 'property_id', 'unit_number', 'unit_type',
             'floor', 'block_number', 'bedrooms', 'bathrooms',
             'size_sqft', 'rent_amount', 'deposit_amount',
             'furnished', 'water_included', 'electricity_included',
             'utility_charge', 'amenities', 'description', 'status'],
            ['Sunshine Apartments', '', 'A101', '1 Bedroom',
             '1', 'A', '1', '1',
             '45', '25000', '50000',
             '0', '1', '0',
             '0', 'Balcony, Built-in wardrobes', 'Spacious unit', 'available']
        );
    });

    $router->post('units/import', function () use ($db, $parseCsv, $parseBool) {
        ApiAuth::requireScope($db, 'write:units');

        $rows = $parseCsv();
        if (is_string($rows)) ApiResponse::badRequest($rows);

        $imported      = 0;
        $skipped       = 0;
        $errors        = [];
        $propertyCache = []; // name → id

        foreach ($rows as $i => $row) {
            $rowNum   = $i + 2;
            $unitNum  = trim($row['unit_number'] ?? '');
            $unitType = trim($row['unit_type']   ?? '');
            $rentRaw  = trim($row['rent_amount'] ?? '');

            if (!$unitNum) {
                $errors[] = ['row' => $rowNum, 'error' => 'unit_number is required.'];
                $skipped++;
                continue;
            }
            if (!$unitType) {
                $errors[] = ['row' => $rowNum, 'error' => 'unit_type is required.'];
                $skipped++;
                continue;
            }
            if (!is_numeric($rentRaw)) {
                $errors[] = ['row' => $rowNum, 'error' => 'rent_amount must be a number.'];
                $skipped++;
                continue;
            }

            // Resolve property: prefer explicit property_id, fall back to property_name
            $propertyId = null;
            $propIdRaw  = trim($row['property_id'] ?? '');
            $propName   = trim($row['property_name'] ?? '');

            if ($propIdRaw !== '' && is_numeric($propIdRaw)) {
                $propertyId = (int)$propIdRaw;
            } elseif ($propName !== '') {
                if (!array_key_exists($propName, $propertyCache)) {
                    $pStmt = $db->prepare(
                        "SELECT id FROM properties WHERE name = ? AND status != 'deleted' LIMIT 1"
                    );
                    $pStmt->execute([$propName]);
                    $propertyCache[$propName] = $pStmt->fetchColumn() ?: null;
                }
                $propertyId = $propertyCache[$propName] !== null ? (int)$propertyCache[$propName] : null;
            }

            if (!$propertyId) {
                $errors[] = ['row' => $rowNum, 'error' => 'Property not found. Provide a valid property_id or property_name.'];
                $skipped++;
                continue;
            }

            // Skip duplicate unit_number within the same property
            $dupStmt = $db->prepare("SELECT id FROM units WHERE property_id = ? AND unit_number = ?");
            $dupStmt->execute([$propertyId, $unitNum]);
            if ($dupStmt->fetchColumn()) {
                $errors[] = ['row' => $rowNum, 'error' => "Unit $unitNum already exists in this property (skipped)."];
                $skipped++;
                continue;
            }

            $depositRaw = trim($row['deposit_amount']  ?? '');
            $utilRaw    = trim($row['utility_charge']  ?? '');
            $sqftRaw    = trim($row['size_sqft']       ?? '');
            $floorRaw   = trim($row['floor']           ?? '');
            $bedsRaw    = trim($row['bedrooms']        ?? '');
            $bathsRaw   = trim($row['bathrooms']       ?? '');

            $allowed = [
                'property_id' => $propertyId,
                'unit_number' => $unitNum,
                'unit_type'   => $unitType,
                'rent_amount' => (float)$rentRaw,
                'status'      => trim($row['status'] ?? '') ?: 'available',
            ];

            if ($floorRaw !== '' && is_numeric($floorRaw))   $allowed['floor']          = (int)$floorRaw;
            if (($v = trim($row['block_number'] ?? '')) !== '') $allowed['block_number']  = $v;
            if ($bedsRaw  !== '' && is_numeric($bedsRaw))    $allowed['bedrooms']        = (int)$bedsRaw;
            if ($bathsRaw !== '' && is_numeric($bathsRaw))   $allowed['bathrooms']       = (int)$bathsRaw;
            if ($sqftRaw  !== '' && is_numeric($sqftRaw))    $allowed['size_sqft']       = (float)$sqftRaw;
            if ($depositRaw !== '' && is_numeric($depositRaw)) $allowed['deposit_amount'] = (float)$depositRaw;
            if ($utilRaw  !== '' && is_numeric($utilRaw))    $allowed['utility_charge']  = (float)$utilRaw;
            if (($b = $parseBool($row['furnished']            ?? '')) !== null) $allowed['furnished']            = $b;
            if (($b = $parseBool($row['water_included']       ?? '')) !== null) $allowed['water_included']       = $b;
            if (($b = $parseBool($row['electricity_included'] ?? '')) !== null) $allowed['electricity_included'] = $b;
            if (($v = trim($row['amenities']   ?? '')) !== '') $allowed['amenities']   = $v;
            if (($v = trim($row['description'] ?? '')) !== '') $allowed['description'] = $v;

            try {
                $cols   = implode(', ', array_keys($allowed));
                $places = implode(', ', array_fill(0, count($allowed), '?'));
                $db->prepare("INSERT INTO units ($cols) VALUES ($places)")->execute(array_values($allowed));
                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNum, 'error' => $e->getMessage()];
                $skipped++;
            }
        }

        ApiResponse::ok(
            compact('imported', 'skipped', 'errors'),
            "$imported unit(s) imported, $skipped skipped."
        );
    });

    // ════════════════════════════════════════════════════════════
    // TENANTS
    // ════════════════════════════════════════════════════════════

    $router->get('tenants/import/template', function () use ($csvTemplate, $db) {
        ApiAuth::requireScope($db, 'write:tenants');
        ($csvTemplate)(
            'tenants_template.csv',
            ['first_name', 'last_name', 'email', 'phone', 'id_number',
             'id_type', 'gender', 'nationality', 'dob',
             'occupation', 'employer', 'monthly_income',
             'emergency_contact_name', 'emergency_contact_phone',
             'next_of_kin_name', 'next_of_kin_phone', 'notes'],
            ['Jane', 'Doe', 'jane@example.com', '0712345678', '32145678',
             'national_id', 'female', 'Kenyan', '1990-05-15',
             'Software Engineer', 'Acme Ltd', '80000',
             'Bob Doe', '0798765432',
             'Mary Doe', '0756789012', 'Reliable tenant']
        );
    });

    $router->post('tenants/import', function () use ($db, $parseCsv) {
        ApiAuth::requireScope($db, 'write:tenants');

        $rows = $parseCsv();
        if (is_string($rows)) ApiResponse::badRequest($rows);

        $svc      = new TenantService($db);
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            // Support either separate first/last columns or a single 'name' column
            $firstName = trim($row['first_name'] ?? '');
            $lastName  = trim($row['last_name']  ?? '');
            if (!$firstName && !$lastName && !empty($row['name'])) {
                $parts     = explode(' ', trim($row['name']), 2);
                $firstName = $parts[0];
                $lastName  = $parts[1] ?? '';
            }

            $email    = strtolower(trim($row['email']     ?? ''));
            $phone    = trim($row['phone']    ?? '');
            $idNumber = trim($row['id_number'] ?? '');

            if (!$firstName) {
                $errors[] = ['row' => $rowNum, 'error' => 'first_name (or name) is required.'];
                $skipped++;
                continue;
            }
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNum, 'error' => 'A valid email is required.'];
                $skipped++;
                continue;
            }
            if (!$phone) {
                $errors[] = ['row' => $rowNum, 'error' => 'phone is required.'];
                $skipped++;
                continue;
            }
            if (!$idNumber) {
                $errors[] = ['row' => $rowNum, 'error' => 'id_number is required.'];
                $skipped++;
                continue;
            }

            // Skip if email or ID number already exists
            $emailExists = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $emailExists->execute([$email]);
            if ((int)$emailExists->fetchColumn() > 0) {
                $errors[] = ['row' => $rowNum, 'error' => "Email already registered (skipped): $email"];
                $skipped++;
                continue;
            }

            $idHash    = Encryptor::hash($idNumber);
            $idExists  = $db->prepare("SELECT COUNT(*) FROM tenants WHERE id_number_hash = ?");
            $idExists->execute([$idHash]);
            if ((int)$idExists->fetchColumn() > 0) {
                $errors[] = ['row' => $rowNum, 'error' => "ID number already registered (skipped): $idNumber"];
                $skipped++;
                continue;
            }

            $data = [
                'first_name'               => $firstName,
                'last_name'                => $lastName,
                'email'                    => $email,
                'phone'                    => $phone,
                'id_number'                => $idNumber,
                'id_type'                  => trim($row['id_type']                  ?? '') ?: null,
                'gender'                   => trim($row['gender']                   ?? '') ?: null,
                'nationality'              => trim($row['nationality']              ?? '') ?: null,
                'dob'                      => trim($row['dob']                      ?? '') ?: null,
                'occupation'               => trim($row['occupation']               ?? '') ?: null,
                'employer'                 => trim($row['employer']                 ?? '') ?: null,
                'monthly_income'           => is_numeric($row['monthly_income'] ?? '') ? $row['monthly_income'] : null,
                'emergency_contact_name'   => trim($row['emergency_contact_name']   ?? '') ?: null,
                'emergency_contact_phone'  => trim($row['emergency_contact_phone']  ?? '') ?: null,
                'next_of_kin_name'         => trim($row['next_of_kin_name']         ?? '') ?: null,
                'next_of_kin_phone'        => trim($row['next_of_kin_phone']        ?? '') ?: null,
                'notes'                    => trim($row['notes']                    ?? '') ?: null,
                'status'                   => trim($row['status']                   ?? '') ?: 'active',
            ];
            // Strip nulls so TenantService::create() doesn't see them as provided
            $data = array_filter($data, fn($v) => $v !== null);

            $res = $svc->create($data);
            if ($res['success']) {
                $imported++;
            } else {
                $errors[] = ['row' => $rowNum, 'error' => $res['message']];
                $skipped++;
            }
        }

        ApiResponse::ok(
            compact('imported', 'skipped', 'errors'),
            "$imported tenant(s) imported, $skipped skipped."
        );
    });
}
