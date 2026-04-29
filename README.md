# XTec Product Navigation

Modulo per PrestaShop 8 che aggiunge, nella pagina prodotto, i pulsanti **Precedente** e **Successivo** basati sull'ultimo listing visitato dall'utente.

Contesti supportati:

- **categoria**
- **brand / manufacturer**
- **ricerca**

## Come funziona oggi

1. Quando l'utente apre una pagina listing, il modulo intercetta lato server il risultato della query prodotti.
2. Se il contesto è supportato, salva nella sessione server-side gestita da PrestaShop il contesto del listing, associato a una chiave deterministica.
3. Nel browser, la chiave del listing corrente viene salvata in `sessionStorage`, quindi resta separata per ogni tab.
4. Nella pagina prodotto, il modulo legge quella chiave dal tab corrente e chiede al proprio endpoint il blocco `prev/next`.
5. Il server carica progressivamente solo le pagine del listing che servono per calcolare `prev/next`, senza ricostruire subito tutto il dominio.

Per supportare correttamente più tab aperti:

- ogni listing genera una `context key`
- ogni tab conserva la propria chiave in `sessionStorage`
- la pagina prodotto usa quella chiave per leggere il contesto corretto dalla sessione
- non viene aggiunto nessun parametro superfluo all'URL del prodotto

## Vantaggi di questa architettura

- niente raffiche di chiamate AJAX dal client
- niente parametri tecnici nell'URL del prodotto
- niente seconda query completa upfront del listing
- contesto coerente con il listing del tab corrente
- supporto corretto a più tab con listing diversi aperti insieme
- minore esposizione lato sicurezza
- niente uso diretto di `$_SESSION` nativa nel modulo

## Limite di sicurezza/performance

Per evitare query o contesti troppo pesanti, il modulo attiva la navigazione solo se il listing contiene al massimo **500 prodotti**.

Se il listing supera questa soglia:

- il modulo non salva il contesto
- la navigazione `prev/next` non viene mostrata per quel caso
- viene scritto un log diagnostico in PrestaShop

La soglia è definita in:

- [xtec_productnav.php](/opt/prestashop8/modules/xtec_productnav/xtec_productnav.php:24)

## Hook usati

- `displayHeader`
- `displayFooterProduct`
- `actionProductSearchProviderRunQueryAfter`

## File principali

- `xtec_productnav.php`
- `controllers/front/navigation.php`
- `views/js/front.js`
- `views/css/front.css`
- `views/templates/hook/nav.tpl`
- `upgrade/upgrade-1.1.1.php`
- `upgrade/upgrade-1.1.3.php`

## Installazione / aggiornamento

1. Installa o aggiorna il modulo.
2. Su versioni già installate, l'upgrade `1.1.3` registra automaticamente gli hook necessari e riallinea la configurazione base.
3. Svuota la cache di PrestaShop.
4. In produzione, se usi più nodi web, assicurati di avere sessioni condivise o sticky session.

## Comportamento atteso

- se l'utente entra in un prodotto da una categoria, `prev/next` usa quell'ordine
- se l'utente cambia categoria, brand o filtri, il contesto viene aggiornato
- se l'utente apre un prodotto direttamente senza passare da un listing, la navigazione non compare

## Note operative

- Il modulo usa la sessione server-side di PrestaShop, non una tabella custom per i dati utente.
- Il contesto viene arricchito progressivamente per pagina quando serve attraversare i confini del listing.
- Il JS gestisce:
  - salvataggio della chiave di contesto per tab
  - fetch del blocco navigazione in pagina prodotto
  - UI mobile del pannello
