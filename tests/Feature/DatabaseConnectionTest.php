<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_wayalong_testing_database_connection(): void
    {
        // 1. Confirm Laravel is running in the testing environment.
        $this->assertEquals(
            'testing',
            app()->environment(),
            'Laravel must be running in the testing environment.'
        );

        // 2. Confirm the MySQL / MariaDB driver.
        $this->assertEquals(
            'mysql',
            DB::getDefaultConnection(),
            'Tests must use the MySQL/MariaDB connection.'
        );

        // 3. Critical safety check:
        // Never allow tests to use the development database.
        $this->assertEquals(
            'wayalong_testing',
            DB::connection()->getDatabaseName(),
            'Tests must use wayalong_testing, never wayalong.'
        );

        // 4. Verify the database connection works.
        $result = DB::selectOne(
            'SELECT 1 AS connection_test'
        );

        $this->assertEquals(
            1,
            (int) $result->connection_test,
            'The database connection query failed.'
        );

        // 5. Verify the actual authenticated MariaDB account.
        $account = DB::selectOne(
            'SELECT CURRENT_USER() AS account'
        );

        $this->assertEquals(
            'wayalong_test@localhost',
            $account->account,
            'Tests must connect using the restricted wayalong_test account.'
        );

        // 6. Verify that the testing account has no
        // explicit schema-level privileges on the
        // development database.
        $developmentPrivileges = DB::selectOne(
            "SELECT COUNT(*) AS total
             FROM information_schema.SCHEMA_PRIVILEGES
             WHERE TABLE_SCHEMA = 'wayalong'
               AND GRANTEE LIKE '%wayalong_test%'"
        );

        $this->assertEquals(
            0,
            (int) $developmentPrivileges->total,
            'The testing account must not have privileges on wayalong.'
        );

        // 7. Verify the testing account cannot read
        // from the development database.
        //
        // Attempt to read its metadata through
        // information_schema. Unauthorized tables
        // should not be visible to this account.
        $developmentTables = DB::selectOne(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'wayalong'"
        );

        $this->assertEquals(
            0,
            (int) $developmentTables->total,
            'The testing account can see development database tables.'
        );
    }
}
