<?php

// 1. ANUANI ZA API ZAKO MBILI (OKX na Coinbase)
$url1 = "https://www.okx.com/api/v5/market/books-full?instId=BTC-USDT-SWAP&sz=5000";
$url2 = "https://api.exchange.coinbase.com/products/BTC-USD/book?level=2";

// ====================================================================================
// HATUA YA 2: KUVUTA CONTRACT SIZE KIOTOMATIKI KUTOKA OKX PUBLIC API
// ====================================================================================
$contract_value = 1.0; 
$api_mikataba = "https://www.okx.com/api/v5/public/instruments?instType=SWAP";

$majibu_mikataba_mbichi = @file_get_contents($api_mikataba);
if ($majibu_mikataba_mbichi !== false) {
    $mikataba_iliyotafsiriwa = json_decode($majibu_mikataba_mbichi, true);
    if (isset($mikataba_iliyotafsiriwa['code']) && $mikataba_iliyotafsiriwa['code'] == "0" && !empty($mikataba_iliyotafsiriwa['data'])) {
        foreach ($mikataba_iliyotafsiriwa['data'] as $chombo) {
            if ($chombo['instId'] == "BTC-USDT-SWAP") {
                $contract_value = (float)$chombo['ctVal']; 
                break;
            }
        }
    }
}

// ====================================================================================
// HATUA YA 3: PATA DATA KUTOKA API YA KWANZA (OKX)
// ====================================================================================
$json1 = @file_get_contents($url1);
$data1 = json_decode($json1, true);
$bids1 = $data1['data'][0]['bids'] ?? [];
$asks1 = $data1['data'][0]['asks'] ?? [];

// Tunaunda safu mpya zenye muundo rasmi ili kuepuka vurugu za index
$processed_bids = [];
$processed_asks = [];

foreach ($bids1 as $bid) {
    $processed_bids[] = [
        'price' => (float)$bid[0],
        'amount' => (float)$bid[1],
        'exchange' => 'OKX'
    ];
}
foreach ($asks1 as $ask) {
    $processed_asks[] = [
        'price' => (float)$ask[0],
        'amount' => (float)$ask[1],
        'exchange' => 'OKX'
    ];
}

// ====================================================================================
// HATUA YA 4: PATA DATA KUTOKA API YA PILI (Coinbase)
// ====================================================================================
$chaguzi = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: WhaleMonitor/1.0\r\n" // User agent safi
    ]
];
$muktadha = stream_context_create($chaguzi);
$json2 = @file_get_contents($url2, false, $muktadha);
$data2 = json_decode($json2, true);
$bids2 = $data2['bids'] ?? [];
$asks2 = $data2['asks'] ?? [];

foreach ($bids2 as $bid) {
    $processed_bids[] = [
        'price' => (float)$bid[0],
        'amount' => (float)$bid[1],
        'exchange' => 'Coinbase'
    ];
}
foreach ($asks2 as $ask) {
    $processed_asks[] = [
        'price' => (float)$ask[0],
        'amount' => (float)$ask[1],
        'exchange' => 'Coinbase'
    ];
}

// ====================================================================================
// HATUA YA 6: KUPANGA BEI (Spaceship Operator <=>)
// ====================================================================================
// A: Wanunuzi (Bids): Kutoka kubwa kwenda ndogo
usort($processed_bids, function($a, $b) {
    return $b['price'] <=> $a['price'];
});

// B: Wauzaji (Asks): Kutoka ndogo kwenda kubwa
usort($processed_asks, function($a, $b) {
    return $a['price'] <=> $b['price'];
});

// ====================================================================================
// HATUA YA 7: CHUJIO LA HESABU ($900K kama ilivyo kwenye kodi yako)
// ====================================================================================
$bids_final = [];
$asks_final = [];
$min_value = 900000; // Ulizibadilisha kutoka 400k kwenda 900k hapa, nimeziacha hivi hivi

// --- CHUJA BIDS ---
foreach ($processed_bids as $bid) {
    $price = $bid['price'];
    $amount = $bid['amount'];
    $exchange = $bid['exchange'];

    $real_amount = ($exchange === "OKX") ? ($amount * $contract_value) : $amount;
    $value = $price * $real_amount;

    if ($value >= $min_value) {
        $bids_final[] = [
            "bei" => $price,
            "kiasi" => $real_amount,
            "dola" => $value,
            "exchange" => $exchange
        ];
    }
}

// --- CHUJA ASKS ---
foreach ($processed_asks as $ask) {
    $price = $ask['price'];
    $amount = $ask['amount'];
    $exchange = $ask['exchange'];

    $real_amount = ($exchange === "OKX") ? ($amount * $contract_value) : $amount;
    $value = $price * $real_amount;

    if ($value >= $min_value) {
        $asks_final[] = [
            "bei" => $price,
            "kiasi" => $real_amount,
            "dola" => $value,
            "exchange" => $exchange
        ];
    }
}

// ====================================================================================
// HATUA YA 8: KUKATA DATA ILI IWE ODA 200 TU
// ====================================================================================
$bids_final = array_slice($bids_final, 0, 200);
$asks_final = array_slice($asks_final, 0, 200);

// URL ya Cloudflare Worker
$worker_url = "https://heatmap.simotizi255.workers.dev";

$data_to_send = [
    "bids" => $bids_final,
    "asks" => $asks_final
];

$json_data = json_encode($data_to_send);

// Anzisha cURL
$ch = curl_init($worker_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_data)
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo 'Kosa la cURL: ' . curl_error($ch);
} else {
    echo "<h3>Msimbo wa Hali (HTTP Code): $http_code</h3>";
    echo "<h3>Majibu kutoka Cloudflare:</h3>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

curl_close($ch);
?>