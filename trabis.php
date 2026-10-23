<?php
//Netdirekt-Trabis WHMCS API v0.6
define('api_key', '*************');

/**
 * Doğru UTF-8 kodlanmış domain adını döndürür.
 *
 * tbldomains.domain kolonu `latin1` charset ile tanımlı olmasına rağmen içine
 * UTF-8 byte'ları kaydedildiği için, WHMCS utf8 bağlantı ile okurken MySQL kolonu
 * latin1->utf8 transcode edip Türkçe karakterleri bozuyor (ş->ÅŸ, ü->Ã¼). Bu
 * bozuk ad API'ye gidince DOMAIN_NOT_FOUND / UNKNOWN_ERROR alınıyor.
 *
 * Çözüm: ham byte'ları `CONVERT(domain USING binary)` ile oku (bağlantı
 * charset'inden bağımsız) -> geçerli UTF-8 elde edilir.
 *
 * @param array $params WHMCS parametreleri (domainid / domainname)
 * @return string Doğru UTF-8 domain adı
 */
function trabis_getDomainName($params)
{
    // 1) Tercih edilen yol: domainid ile DB'den ham (binary) byte'ları oku
    if (!empty($params['domainid'])) {
        try {
            $row = \WHMCS\Database\Capsule::table('tbldomains')
                ->where('id', $params['domainid'])
                ->selectRaw('CONVERT(`domain` USING binary) AS rawdomain')
                ->first();
            if ($row && !empty($row->rawdomain) && mb_check_encoding($row->rawdomain, 'UTF-8')) {
                return $row->rawdomain;
            }
        } catch (\Throwable $e) {
            // Capsule erişilemiyorsa aşağıdaki fallback'e düş
        }
    }

    // 2) Fallback: WHMCS'in verdiği domainname mojibake ise geri çevir
    $domain = isset($params['domainname']) ? $params['domainname'] : '';
    if ($domain !== '' && preg_match('/[ÃÅÂÄ]/u', $domain)) {
        $fixed = @mb_convert_encoding($domain, 'Windows-1252', 'UTF-8');
        if ($fixed !== false && $fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
            return $fixed;
        }
    }
    return $domain;
}

/**
 * Trabis API çağrılarını tblapilog tablosuna kaydeder
 *
 * @param string $action API aksiyonu (register_domain, renew_domain, get_ns, vb.)
 * @param array $request Gönderilen request verileri
 * @param array|string $response API'den dönen yanıt
 * @param array $params WHMCS parametreleri (domain bilgisi için)
 * @return void
 */
function trabis_LogApiCall($action, $request, $response, $params = [])
{
    // API key'i loglardan gizle
    $logRequest = $request;
    if (isset($logRequest['api_key'])) {
        $logRequest['api_key'] = '***HIDDEN***';
    }

    // Response'u array'e çevir
    if (is_string($response)) {
        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $responseData = ['raw_response' => $response];
        }
    } else {
        $responseData = $response;
    }

    // Domain adını al
    $domainName = isset($params['domainname']) ? $params['domainname'] : (isset($request['domain']) ? $request['domain'] : 'N/A');

    // WHMCS logModuleCall fonksiyonunu kullan (tblapilog'a yazar)
    logModuleCall(
        'trabis',                           // module
        $action,                            // action
        $logRequest,                        // request (array olarak)
        $responseData,                      // response (array olarak)
        $responseData,                      // processed response
        [api_key]                           // replace vars: anahtar istek VE yanıtın her yerinde maskelenir
    );
}

/**
 * WHMCS'te seçili ülkeyi Trabis API countryId değerine çevirir.
 *
 * WHMCS normalde ISO 3166-1 alpha-2 kodu gönderir (TR, GR, US...). Ancak
 * tblclients.country kolonunda eski/import edilmiş kayıtlarda serbest metin
 * ülke adları da bulunuyor (GERMANY, FRANCE, AUSTRI, CHINAESE TAIBEI...).
 * Bu fonksiyon her iki durumu da karşılar; eşleşme yoksa Trabis'in
 * "unknownCountry" değeri olan 888 döner.
 *
 * @param string $country WHMCS ülke değeri (ISO2 kodu veya ülke adı)
 * @return string Trabis countryId
 */
function trabis_resolveCountryId($country)
{
    $country = trim((string) $country);
    if ($country === '') {
        return '888'; // unknownCountry
    }

    // ISO 3166-1 alpha-2 -> Trabis countryId (asıl/öncelikli eşleme)
    static $iso2 = [
        'AW' => '1',   'AF' => '2',   'AO' => '3',   'AI' => '4',   'AL' => '5',
        'AD' => '6',   'AN' => '7',   'AE' => '8',   'AR' => '9',   'AM' => '10',
        'AS' => '11',  'AQ' => '12',  'TF' => '13',  'AG' => '14',  'AU' => '15',
        'AT' => '16',  'AZ' => '17',  'BI' => '18',  'BE' => '19',  'BJ' => '20',
        'BF' => '21',  'BD' => '22',  'BG' => '23',  'BH' => '24',  'BS' => '25',
        'BA' => '26',  'BY' => '27',  'BZ' => '28',  'BM' => '29',  'BO' => '30',
        'BR' => '31',  'BB' => '32',  'BN' => '33',  'BT' => '34',  'BV' => '35',
        'BW' => '36',  'CF' => '37',  'CA' => '38',  'CC' => '39',  'CH' => '40',
        'CL' => '41',  'CN' => '42',  'CI' => '43',  'CM' => '44',  'CG' => '45',
        'CK' => '46',  'CO' => '47',  'KM' => '48',  'CV' => '49',  'CR' => '50',
        'CU' => '51',  'CX' => '52',  'KY' => '53',  'CY' => '54',  'CZ' => '55',
        'DE' => '56',  'DJ' => '57',  'DM' => '58',  'DK' => '59',  'DO' => '60',
        'DZ' => '61',  'EC' => '62',  'EG' => '63',  'ER' => '64',  'EH' => '65',
        'ES' => '66',  'EE' => '67',  'ET' => '68',  'FI' => '69',  'FJ' => '70',
        'FK' => '71',  'FR' => '72',  'FO' => '73',  'FM' => '74',  'FX' => '75',
        'GA' => '76',  'GB' => '77',  'GE' => '78',  'GH' => '79',  'GI' => '80',
        'GN' => '81',  'GP' => '82',  'GM' => '83',  'GW' => '84',  'GQ' => '85',
        'GR' => '86',  'GD' => '87',  'GL' => '88',  'GT' => '89',  'GF' => '90',
        'GU' => '91',  'GY' => '92',  'HK' => '93',  'HM' => '94',  'HN' => '95',
        'HR' => '96',  'HT' => '97',  'HU' => '98',  'ID' => '99',  'IN' => '100',
        'IO' => '101', 'IE' => '102', 'IR' => '103', 'IQ' => '104', 'IS' => '105',
        'IL' => '106', 'IT' => '107', 'JM' => '108', 'JO' => '109', 'JP' => '110',
        'KZ' => '111', 'KE' => '112', 'KG' => '113', 'KH' => '114', 'KI' => '115',
        'KN' => '116', 'KR' => '117', 'KW' => '118', 'LA' => '119', 'LB' => '120',
        'LR' => '121', 'LY' => '122', 'LC' => '123', 'LI' => '124', 'LK' => '125',
        'LS' => '126', 'LT' => '127', 'LU' => '128', 'LV' => '129', 'MO' => '130',
        'MA' => '131', 'MC' => '132', 'MD' => '133', 'MG' => '134', 'MV' => '135',
        'MX' => '136', 'MH' => '137', 'MK' => '138', 'ML' => '139', 'MT' => '140',
        'MM' => '141', 'MN' => '142', 'MP' => '143', 'MZ' => '144', 'MR' => '145',
        'MS' => '146', 'MQ' => '147', 'MU' => '148', 'MW' => '149', 'MY' => '150',
        'YT' => '151', 'NA' => '152', 'NC' => '153', 'NE' => '154', 'NF' => '155',
        'NG' => '156', 'NI' => '157', 'NU' => '158', 'NL' => '159', 'NO' => '160',
        'NP' => '161', 'NR' => '162', 'NZ' => '163', 'OM' => '164', 'PK' => '165',
        'PA' => '166', 'PN' => '167', 'PE' => '168', 'PH' => '169', 'PW' => '170',
        'PG' => '171', 'PL' => '172', 'PR' => '173', 'KP' => '174', 'PT' => '175',
        'PY' => '176', 'PF' => '177', 'QA' => '178', 'RE' => '179', 'RO' => '180',
        'RU' => '181', 'RW' => '182', 'SA' => '183', 'SD' => '184', 'SN' => '185',
        'SG' => '186', 'GS' => '187', 'SH' => '188', 'SJ' => '189', 'SB' => '190',
        'SL' => '191', 'SV' => '192', 'SM' => '193', 'SO' => '194', 'PM' => '195',
        'ST' => '196', 'SR' => '197', 'SK' => '198', 'SI' => '199', 'SE' => '200',
        'SZ' => '201', 'SC' => '202', 'SY' => '203', 'TC' => '204', 'TD' => '205',
        'TG' => '206', 'TH' => '207', 'TJ' => '208', 'TK' => '209', 'TM' => '210',
        'TL' => '211', 'TO' => '212', 'TT' => '213', 'TN' => '214', 'TR' => '215',
        'TV' => '216', 'TW' => '217', 'TZ' => '218', 'UG' => '219', 'UA' => '220',
        'UM' => '221', 'UY' => '222', 'US' => '223', 'UZ' => '224', 'VA' => '225',
        'VC' => '226', 'VE' => '227', 'VG' => '228', 'VI' => '229', 'VN' => '230',
        'VU' => '231', 'WF' => '232', 'WS' => '233', 'YE' => '234', 'YU' => '235',
        'ZA' => '236', 'ZR' => '237', 'ZM' => '238', 'ZW' => '239',
        // Eski/alternatif ISO kodları
        'UK' => '77',  // United Kingdom (bazı WHMCS sürümleri UK kullanır)
        'CD' => '237', // DR Kongo (eski Zaire)
        'TP' => '211', // East Timor (eski kod)
    ];

    // Serbest metin ülke adları (eski/import kayıtlar) -> Trabis countryId.
    // Sadece tblclients.country'de fiilen görülen değerler + sık varyantlar.
    static $names = [
        'TURKS AND CAICOS ISLANDS' => '204', 'GERMANY' => '56',
        'AUSTRI' => '16', 'AUSTRIA' => '16', 'FRANCE' => '72',
        'BULGARIA' => '23', 'BOUVET ISLAND' => '35', 'BOTSWANA' => '36',
        'NETHERLANDS' => '159', 'ALBANIA' => '5', 'AFGHANISTAN' => '2',
        'ARUBA' => '1', 'BERMUDA' => '29', 'BRAZIL' => '31',
        'TURKMENISTAN' => '210', 'UNITED KINGDOM' => '77',
        'UNITED STATES' => '223', 'UNITED STATES OF AMERICA' => '223',
        'MALTA' => '140', 'SUDAN' => '184', 'TRINIDAD AND TOBAGO' => '213',
        'BARBADOS' => '32', 'FRENCH GUIANA' => '90', 'BANGLADESH' => '22',
        'ANGOLA' => '3', 'DENMARK' => '59', 'ZAMBIA' => '238',
        'BAHRAIN' => '24', 'CANADA' => '38', 'CZECH REPUBLIC' => '55',
        'ITALY' => '107', 'LATVIA' => '129', 'CHINAESE TAIBEI' => '217',
        'TAIWAN' => '217', 'VANUATU' => '231', 'IRELAND' => '102',
        'ANDORRA' => '6', 'GREECE' => '86', 'ARGENTINA' => '9',
        'MALAWI' => '149', 'SAUDI ARABIA' => '183', 'YUGOSLAVIA' => '235',
        'TURKEY' => '215', 'TURKIYE' => '215', 'TÜRKIYE' => '215',
    ];

    $key = strtoupper($country);

    // 1) İki harfli ISO kodu
    if (strlen($country) === 2 && isset($iso2[$key])) {
        return $iso2[$key];
    }
    // 2) Serbest metin ülke adı
    if (isset($names[$key])) {
        return $names[$key];
    }
    // 3) Defansif: 2 harf olmasa da ISO tablosunda dene
    if (isset($iso2[$key])) {
        return $iso2[$key];
    }

    return '888'; // unknownCountry
}

function trabis_RegisterDomain($params)
{

    $phone = $params['phonenumber'];
    $phoneLength = strlen($phone);
    if ($phoneLength == '11' && substr($phone, 0, 1) == '0') {
        $phone = '9' . $phone;
    } elseif ($phoneLength == '13' && substr($phone, 0, 2) == '+9') {
        $phone = substr($phone, 1);
    }
    $fixedPhone = substr($phone, 0, 2) . '-' . substr($phone, 2, 3) . '-' . substr($phone, 5, 7);

    $nameserver1 = $params["ns1"];
    $nameserver2 = $params["ns2"];
    $nameserver3 = $params["ns3"];
    $nameserver4 = $params["ns4"];
    $nameserver5 = $params["ns5"];

    if (($nameserver1 == "" || $nameserver2 == "")) {
        $nameserver1 = "izm.netdirekt.com.tr";
        $nameserver2 = "ist.netdirekt.com.tr";
        $nameserver3 = "frk.netdirekt.com.tr";
        $nameserver4 = "ams.netdirekt.com.tr";
        $nameserver5 = null;
    }

    // WHMCS'te seçili ülkeyi Trabis countryId'sine çevir (eski varsayılan: 215/Türkiye)
    $countryId = trabis_resolveCountryId($params['country']);

    $post = array(
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params),
        'duration' => $params["regperiod"],
        'email' => $params["email"],
        'countryId' => $countryId,
        'address1' => $params["address1"],
        'address2' => $params["address2"],
        'phone' => $fixedPhone,
        'fax' => null,
        'zipCode' => $params["postcode"],
        'nsName' => array(
            '0' => $nameserver1,
            '1' => $nameserver2,
            '2' => $nameserver3,
            '3' => $nameserver4,
            '4' => $nameserver5
        )
    );

    $hizmet_turu = $params['customfields5'];
    if ($hizmet_turu == 'Bireysel') {
        $type = '1';
        $post['name'] = "{$params["firstname"]} {$params["lastname"]}";
        $post['citizenId'] = $params["customfields4"];
    } elseif ($hizmet_turu == 'Kurumsal') {
        $type = '2';
        $client = new WHMCS\Client($params["userid"]);
        $details = $client->getDetails($contactid);
        $organization = $details['companyname'];
        $post['name'] = "{$params["firstname"]} {$params["lastname"]}"; //Yetkili Kişi
        $post['organization'] = $organization;
        $post['taxOffice'] = $params["customfields2"];
        $post['taxNumber'] = $params["customfields3"];
    }
    $post['type'] = $type;

    // cityId: yalnızca Türkiye (215) için get_cities ile eşleştir.
    // Türkiye dışındaki ülkelerde Trabis şehir listesi geçerli olmadığından 999 set edilir.
    if ($countryId === '215') {
        $cityId = '';

        $get_city = [
            'api_key' => api_key
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/get_cities");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $get_city);
        $result = curl_exec($ch);
        curl_close($ch);
        trabis_LogApiCall('get_cities', $get_city, $result, $params);
        $cities = json_decode($result, true);

        foreach ($cities['cities'] as $city) {
            if ($params['city'] == $city['like']) {
                $cityId = $city['id'];
            }
        }
    } else {
        $cityId = '999';
    }
    $post['cityId'] = $cityId;

    $ch = curl_init();
    // if (substr_count($params['domainname'], '.')  == 1) {
    //     curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/register_domain_gbs");
    // } else {
    //     curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/register_domain");
    // }
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/register_domain");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('register_domain', $post, $result, $params);
    $json = json_decode($result, true);

    if ($json["msg"] == "NEEDS_MANUAL_REGISTRATION_WITH_DOCUMENTS") {
        $array = array(
            'date' => date('Y-m-d'),
            'title' => ".TR (belgeli) alan adı siparişi gelmiştir.",
            'description' => "" . $params['domainname'] . " alan adı için lütfen gerekli belgeler ile trabis panel üzerinden başvuru gerçekleştiriniz.",
            'admin' => '',
            'status' => 'Pending',
            'duedate' => date('Y-m-d', mktime(date('h'), date('i'), date('s'), date('m'), date('d'), date('Y')))
        );
        insert_query('tbltodolist', $array);
    }

    $array = array(
        'date' => date('Y-m-d'),
        'title' => ".TR Alan adı otomatik kayıt edilemedi",
        'description' => "" . $params['domainname'] . " alan adı kaydı sırasında " . $json["msg"] . " hatası alınmıştır. Lütfen hata mesajına göre gerekli aksiyonu alınız.",
        'admin' => '',
        'status' => 'Pending',
        'duedate' => date('Y-m-d', mktime(date('h'), date('i'), date('s'), date('m'), date('d'), date('Y')))
    );
    if ($json["status"] == '1') {
        return "success";
    } else if ($json["status"] == "0") {
        insert_query('tbltodolist', $array);
        return array("error" => $json["msg"]);
    } else {
        insert_query('tbltodolist', $array);
        return array("error" => "error");
    }
}

function trabis_ReNewDomain($params)
{
    $post = array(
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params),
        'duration' => $params["regperiod"]
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/renew_domain");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('renew_domain', $post, $result, $params);
    $json = json_decode($result, true);

    $array = array(
        'date' => date('Y-m-d'),
        'title' => ".TR Alan adı otomatik kayıt edilemedi",
        'description' => "" . $params['domainname'] . " alan adı yenilemesinde" . $json["msg"] . " hatası alınmıştır. Lütfen hata mesajına göre gerekli aksiyonu alınız. Lütfen alan adını yenilemekte acele etmeyiniz. Expire date'i doğru bir şekilde sorgulayıp, yenilenmediyse işlemlere devam ediniz.",
        'admin' => '',
        'status' => 'Pending',
        'duedate' => date('Y-m-d', mktime(date('h'), date('i'), date('s'), date('m'), date('d'), date('Y')))
    );
    if ($json["status"] == '1') {
        return "success";
    } else if ($json["status"] == "0") {

        insert_query('tbltodolist', $array);
        return array("error" => $json["msg"]);
    } else {
        insert_query('tbltodolist', $array);
        return array("error" => "error");
    }
}

function trabis_GetNameservers($params, $detail = null)
{
    $post = [
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params)
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/get_ns");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('get_ns', $post, $result, $params);
    $json = json_decode($result, true);

    $values = array();
    foreach ($json["nameservers"] as $key => $nameServer) {
        $key++;
        if ($nameServer["nsType"] == "other") {
            $values["ns" . $key] = $nameServer["nsName"];
        }
    }
    if ($detail == '1') {
        return $json;
    } else {
        return $values;
    }
}

function trabis_SaveNameservers($params)
{
    $nameserver1 = $params["ns1"];
    if ($nameserver1 == "") $nameserver1 = "empty";
    $nameserver2 = $params["ns2"];
    if ($nameserver2 == "") $nameserver2 = "empty";
    $nameserver3 = $params["ns3"];
    if ($nameserver3 == "") $nameserver3 = "empty";
    $nameserver4 = $params["ns4"];
    if ($nameserver4 == "") $nameserver4 = "empty";
    $nameserver5 = $params["ns5"];
    if ($nameserver5 == "") $nameserver5 = "empty";

    $post = array(
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params),
        'nameservers' => array(
            'ns1' => $nameserver1,
            'ns2' => $nameserver2,
            'ns3' => $nameserver3,
            'ns4' => $nameserver4,
            'ns5' => $nameserver5
        )
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/modify_ns");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('modify_ns', $post, $result, $params);
    $json = json_decode($result, true);

    if ($json["status"] == "0") return array("error" => $json["msg"]);

    return "success";
}

function trabis_RegisterNameserver($params)
{
    $post = array(
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params),
        'ns' => '-1',
        'name' => $params["nameserver"],
        'ip' => $params["ipaddress"]
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/add_child_ns");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('add_child_ns', $post, $result, $params);
    $json = json_decode($result, true);

    if ($json["status"] == "0") return array("error" => $json["msg"]);

    return "success";
}

function trabis_DeleteNameserver($params)
{
    $post = array(
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params),
        'name' => $params["nameserver"],
        'default_ns' => array(
            array("nsName" => "izm.netdirekt.com.tr", "nsIP" => null),
            array("nsName" => "ist.netdirekt.com.tr", "nsIP" => null),
            array("nsName" => "frk.netdirekt.com.tr", "nsIP" => null),
            array("nsName" => "ams.netdirekt.com.tr", "nsIP" => null)
        ),
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/rm_child_ns_v2");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('rm_child_ns_v2', $post, $result, $params);
    $json = json_decode($result, true);

    if ($json["status"] == '1') {
        return "success";
    } else if ($json["status"] == "0") {
        return array("error" => $json["msg"]);
    } else {
        return array("error" => "error");
    }
}

function trabis_GetTrAuthCode($params)
{
    $post = [
        'api_key' => api_key,
        'domain' => trabis_getDomainName($params)
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/getTrAuthCode");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall('getTrAuthCode', $post, $result, $params);
    $json = json_decode($result, true);

    return $json;
}

/**
 * Netdirekt Trabis API'sine POST isteği gönderir ve JSON yanıtını dizi olarak döndürür.
 * Yanıt alınamazsa status=0, msg=API_UNREACHABLE döner.
 *
 * @param string $action API servisi (get_ds_records, add_ds_record, ...)
 * @param array $post Gönderilecek alanlar (api_key otomatik eklenir)
 * @param array $params WHMCS parametreleri (loglama için)
 * @return array
 */
function trabis_ApiPost($action, $post, $params)
{
    $post = array_merge(['api_key' => api_key], $post);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://trabis.netdirekt.com.tr/api/" . $action);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $result = curl_exec($ch);
    curl_close($ch);
    trabis_LogApiCall($action, $post, $result, $params);
    $json = json_decode((string) $result, true);
    return is_array($json) ? $json : ['status' => '0', 'msg' => 'API_UNREACHABLE'];
}

/**
 * Müşteri panelinde alan adı sayfasına "DNSSEC Yönetimi" bağlantısı ekler.
 */
function trabis_ClientAreaCustomButtonArray()
{
    return [
        'DNSSEC Yönetimi' => 'dnssec',
    ];
}

/**
 * DNS sağlayıcısının verdiği DS satırını alanlarına ayırır.
 * Kabul edilen biçimler: "2371 13 2 59D2..." ve "alanadi.com.tr. 3600 IN DS 2371 13 2 59D2...".
 * Digest boşluklarla bölünmüş olabilir. Satır okunamazsa null döner.
 *
 * @param string $satir
 * @return array|null
 */
function trabis_DsSatiriniAyir($satir)
{
    if (preg_match('/(?:^|\s)(\d{1,5})\s+(\d{1,3})\s+(\d)\s+([0-9A-Fa-f][0-9A-Fa-f\s]*)$/', trim($satir), $m)) {
        return [
            'key_tag' => $m[1],
            'alg' => $m[2],
            'digest_type' => $m[3],
            'digest' => preg_replace('/\s+/', '', $m[4]),
        ];
    }
    return null;
}

/**
 * Trabis API hata kodunu müşteriye gösterilecek metne çevirir.
 *
 * @param array $sonuc API yanıtı
 * @return string
 */
function trabis_DnssecHataMetni($sonuc)
{
    $msg = isset($sonuc['msg']) ? (string) $sonuc['msg'] : '';
    $metinler = [
        'DS_RECORD_VALIDATION_FAILED' => 'DS kaydı Trabis tarafından doğrulanamadı. Alan adınızın DNSKEY kaydının yayında olduğundan ve girdiğiniz değerlerin doğru olduğundan emin olun.',
        'MISSING_OR_INVALID_PARAMETERS' => 'Eksik ya da hatalı bilgi.',
        'DOMAIN_NOT_FOUND' => 'Alan adı bayi hesabında bulunamadı.',
        'DNSSEC_CHECK_UNAVAILABLE' => 'DNSSEC kontrol servisine şu anda ulaşılamıyor. Lütfen daha sonra tekrar deneyin.',
        'API_UNREACHABLE' => 'Trabis API\'sine ulaşılamadı. Lütfen daha sonra tekrar deneyin.',
    ];
    $metin = isset($metinler[$msg]) ? $metinler[$msg] : 'İşlem tamamlanamadı: ' . $msg;
    if (!empty($sonuc['detail']) && is_string($sonuc['detail'])) {
        $metin .= ' (' . $sonuc['detail'] . ')';
    }
    return $metin;
}

/**
 * DNSSEC Yönetimi sayfası: DS kayıtlarını listeler, ekler, siler ve DNSSEC zincirini kontrol eder.
 * Şablon: dnssec.tpl (bu dosyayla aynı klasörde, modules/registrars/trabis/).
 *
 * @param array $params WHMCS parametreleri
 * @return array
 */
function trabis_dnssec($params)
{
    $domain = trabis_getDomainName($params);
    $domainId = (int) $params['domainid'];
    $mesaj = '';
    $hata = '';
    $kontrol = null;

    $islem = (isset($_POST['dnssec_islem']) && is_string($_POST['dnssec_islem'])) ? $_POST['dnssec_islem'] : '';
    if ($islem !== '' && function_exists('check_token')) {
        check_token('WHMCS.default');
    }

    if ($islem === 'ekle') {
        $ds = [];
        foreach (['key_tag', 'alg', 'digest_type', 'digest'] as $alan) {
            $ds[$alan] = (isset($_POST[$alan]) && is_string($_POST[$alan])) ? trim($_POST[$alan]) : '';
        }
        $satir = (isset($_POST['ds_satiri']) && is_string($_POST['ds_satiri'])) ? trim($_POST['ds_satiri']) : '';
        if ($satir !== '') {
            $ds = trabis_DsSatiriniAyir($satir);
            if ($ds === null) {
                $hata = 'DS satırı okunamadı. Beklenen biçim: Key Tag, Algoritma, Digest Type ve Digest (örnek: 2371 13 2 59D29AD9...).';
            }
        }
        if ($hata === '') {
            $sonuc = trabis_ApiPost('add_ds_record', array_merge(['domain' => $domain], $ds), $params);
            if (isset($sonuc['status']) && $sonuc['status'] == '1') {
                $mesaj = 'DS kaydı eklendi (Key Tag ' . $sonuc['key_tag'] . '). Kaydın .tr bölgesinde yayınlanması birkaç saat sürebilir.';
            } else {
                $hata = trabis_DnssecHataMetni($sonuc);
            }
        }
    } elseif ($islem === 'sil') {
        $keyTag = (isset($_POST['key_tag']) && is_string($_POST['key_tag'])) ? $_POST['key_tag'] : '';
        $sonuc = trabis_ApiPost('delete_ds_record', ['domain' => $domain, 'key_tag' => $keyTag], $params);
        if (isset($sonuc['status']) && $sonuc['status'] == '1') {
            $mesaj = 'DS kaydı silindi (Key Tag ' . $sonuc['key_tag'] . '). Değişikliğin çözümleyicilere yansıması birkaç saat sürebilir.';
        } else {
            $hata = trabis_DnssecHataMetni($sonuc);
        }
    } elseif ($islem === 'kontrol') {
        $sonuc = trabis_ApiPost('check_dnssec', ['domain' => $domain], $params);
        if (isset($sonuc['status']) && $sonuc['status'] == '1') {
            $kontrol = $sonuc;
        } else {
            $hata = trabis_DnssecHataMetni($sonuc);
        }
    }

    $kayitlar = [];
    $liste = trabis_ApiPost('get_ds_records', ['domain' => $domain], $params);
    if (isset($liste['status']) && $liste['status'] == '1' && isset($liste['ds_records']) && is_array($liste['ds_records'])) {
        $kayitlar = $liste['ds_records'];
    } elseif ($hata === '') {
        $hata = 'DS kayıtları alınamadı. ' . trabis_DnssecHataMetni($liste);
    }

    return [
        'templatefile' => 'dnssec',
        'requirelogin' => true,
        'breadcrumb' => [
            'clientarea.php?action=domaindetails&id=' . $domainId . '&modop=custom&a=dnssec' => 'DNSSEC Yönetimi',
        ],
        'vars' => [
            'domainid' => $domainId,
            'alanadi' => $domain,
            'kayitlar' => $kayitlar,
            'mesaj' => $mesaj,
            'hata' => $hata,
            'kontrol' => $kontrol,
        ],
    ];
}
