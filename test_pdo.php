<?php
try {
    $db = new PDO('sqlite::memory:');
    echo "PDO SQLite is working!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}