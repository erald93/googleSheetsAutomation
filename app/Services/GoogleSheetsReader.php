<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use RuntimeException;

class GoogleSheetsReader
{
    private Sheets $sheets;

    public function __construct()
    {
        $credentials = config('services.google_sheets.credentials');

        if (!$credentials || !file_exists($credentials)) {
            throw new RuntimeException(
                'Google Sheets credentials file was not found.'
            );
        }

        $client = new Client();
        $client->setApplicationName('Google Sheets Importer');
        $client->setAuthConfig($credentials);
        $client->setScopes([
            Sheets::SPREADSHEETS_READONLY,
        ]);

        $this->sheets = new Sheets($client);
    }

    public function read(string $spreadsheetId, string $range): array
    {
        $response = $this->sheets->spreadsheets_values->get(
            $spreadsheetId,
            $range
        );

        return $response->getValues() ?? [];
    }
}
