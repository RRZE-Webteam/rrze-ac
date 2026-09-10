[![Aktuelle Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-ac/main?label=Version)](https://github.com/RRZE-Webteam/rrze-ac)
[![Release Version](https://img.shields.io/github/v/release/rrze-webteam/rrze-ac?label=Release+Version)](https://github.com/rrze-webteam/rrze-ac/releases/)
[![GitHub License](https://img.shields.io/github/license/rrze-webteam/rrze-ac)](https://github.com/RRZE-Webteam/rrze-ac)
[![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-ac)](https://github.com/RRZE-Webteam/rrze-ac/issues)

# RRZE Access-Control

Das Plugin ermöglicht den eingeschränkten Zugriff auf Dateien und Seiten durch benutzerbezogene Funktionen und IP-Adressen.

## Contributors

* RRZE-Webteam, http://www.rrze.fau.de 

## Copyright

GNU General Public License (GPL) Version 3

## Documentation

See documenation at https://www.wp.rrze.fau.de

## Feedback

* https://github.com/RRZE-Webteam/rrze-ac/issues
* webmaster@rrze.fau.de

## Entwicklerhinweise

Zugriffsberechtigungen werden als Website-Option gespeichert und können ausschließlich von Administratoren angelegt oder bearbeitet werden. Die unter „Allgemein“ festgelegte Mindestrolle darf bestehende Berechtigungen in Seiten und Medien auswählen und ändern, jedoch keine Berechtigungsdefinitionen verwalten.

SSO-Optionen erscheinen nur, wenn `rrze-sso/rrze-sso.php` installiert und für die Website oder das Netzwerk aktiviert ist. Das Plugin prüft geschützte Inhalte auch in der WordPress-REST-API, jedoch ausschließlich für die Core-Ressourcen `/wp/v2/pages/{id}` und `/wp/v2/media/{id}`; REST-Endpunkte anderer Plugins werden nicht beeinflusst.

Zentrale technische Konstanten wie Optionsnamen, Transient-Präfixe, Pfade und Cookie-Einstellungen liegen in `includes/Config.php`. Versions- und Plugin-Metadaten werden aus `package.json` in den Plugin-Header und die `readme.txt` synchronisiert. Änderungen an Quell-Assets werden über die npm-Skripte gebaut; erzeugte Dateien im Verzeichnis `build/` werden nicht manuell bearbeitet.

Informative Protokollmeldungen werden nur bei aktivierter Debugging-Option an `rrze.log.info` gesendet. Fehlgeschlagene Passworteingaben werden unabhängig davon an `rrze.log.warning` protokolliert.

### Cookies

Für eine veröffentlichte Seite mit passwortbasierter Zugangsberechtigung setzt das Plugin nach erfolgreicher Passworteingabe den Cookie `rrze_ac_password_{Beitrags-ID}`. Er enthält den verschlüsselten Passwortwert und ermöglicht den erneuten Zugriff auf genau diese Seite, ohne das Passwort erneut einzugeben. Der Cookie gilt eine Stunde, wird nur über HTTPS übertragen, ist nur serverseitig lesbar (`HttpOnly`), verwendet `SameSite=Lax` sowie den von WordPress vorgegebenen Cookie-Pfad und die Cookie-Domain. Bei allen anderen Zugriffsarten setzt das Plugin keine Cookies.
