# trabis-whmcs-api
Bu modül .TR alan adı bayilerimizin, WHMCS araclığı ile Trabis API sine istek göndermesini sağlamaktadır.

- Trabis bayi panelinizde, API Yönetimi menüsünden API anahtarınızı oluşturun ve API isteği gönderecek IP adresinize izin verin.
- Trabis.php ilk sayısında define('api_key', 'BURAYA_API_ANAHTARI_YAZILACAK'); ilgili tanımlamayı yapın.
- Tercihinize göre varsayılan isim sunucularınızı yazın.
- Template dosyası firmaya özel olduğu için paylaşılmamıştır.
- Trabis WHMCS API si, https://trabis.netdirekt.com.tr/api_doc/ adresindeki döküman baz alınarak hazırlanmıştır. İlgili API dökümanına göre kendi geliştirmenizi yapabilirsiniz.
- Alan adı bayiliğiniz yoksa, Netdirekt Müşteri Hizmetlerini (0850 200 88 99) arayarak bilgi talep edebilirsiniz.

27.01.2023

Alan adı transferine imkan veren aşağıdaki servisler, API ye eklenmiştir. WHMCS modülünüzü bu fonksiyonlara göre güncelleyebilirsiniz.

- lockStatus
- lockTransfer
- unlockTransfer
- getAuthCode
- demandTransfer
- approveTransfer

27.02.2023

- get_contact ve modify_contact fonksiyonlarına, admin, ödeme ve teknik sorumlu bilgileri eklenmiştir.
- register_domain fonksiyonuna opsiyonel olarak admin, ödeme ve teknik sorumlu gönderme opsiyonu eklenmiştir.
- setPrivacy fonksiyonu ile whois gizlemesini açma/kapatma özelliği eklenmiştir.

12.03.2024

- trabis_GetTrAuthCode fonksiyonu ile 25.08.2023 ten önce kayıt edilen alan adları için, a.tr transfer kodu alınabilmesi sağlandı

21.05.2024

- trabis_RegisterDomain fonksiyonuna a.tr alan adları gönderilir ise, trabis geçici başvuru sistemine kayıt eklenmesi için düzenleme yapıldı.

