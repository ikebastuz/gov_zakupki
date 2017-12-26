## gov_zakupki
Требования:
PHP v.5.4 +

Конфигурация
```bash
{
    "database" : {
        "username" : "",
        "password" : "",
        "database" : "",
        "collection" : "public"
    },
    "no_okei" : 283,
    "files" : {
        "archive_folder" : "src_files",
        "import_folder" : "import_files",
        "error_log" : "error.log"
    },
    "scan_period" : 86400
}
```

no_okei - id записи для товаров без единиц измерения
archive_folder - папка для загрузки архивов xml
import_folder - папка для файлов xml (в коротую парсер распакует файлы из папки archive_folder по команде load)


Использование:
/parser/load - распаковать файлы из папки archive_folder в папку import_folder
/parser/run - запустить парсер

/product/search/запрос - поиск по продуктам по входжению или ключу
/product/details/код - детальная информация по продукту с характеристиками
/catalog/код - дерево категорий вниз от указанного кода
