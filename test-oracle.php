<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing Oracle Connection...\n\n";

try {
    echo "1. Checking OCI8 extension: ";
    if (extension_loaded('oci8')) {
        echo "✓ LOADED\n";
    } else {
        echo "✗ NOT LOADED\n";
        exit(1);
    }
    
    echo "\n2. Testing database connection...\n";
    $pdo = DB::connection()->getPdo();
    echo "✓ Connection successful!\n";
    
    echo "\n3. Database info:\n";
    echo "   Driver: " . DB::connection()->getDriverName() . "\n";
    echo "   Database: " . DB::connection()->getDatabaseName() . "\n";
    
    echo "\n4. Testing query...\n";
    $result = DB::select('SELECT 1 as test FROM dual');
    echo "   Query result: " . $result[0]->test . "\n";
    
    echo "\n✓ All tests passed!\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
