# XTec Product Navigation

Modulo per PrestaShop 8.2 che aggiunge, nella pagina prodotto, i pulsanti **Precedente** e **Successivo** basati sull'ultima lista prodotti visitata dall'utente:

- **categoria**
- **ricerca**

## Come funziona

1. Nella pagina categoria o ricerca, il JS legge i prodotti visibili nella lista.
2. Salva in `localStorage` l'ordine corrente, con URL, nome, immagine e prezzo.
3. Nella pagina prodotto, il modulo individua il prodotto corrente dentro quella lista.
4. Mostra le card **Indietro / Avanti** con layout responsive.

## Hook usati

- `displayHeader`
- `displayFooterProduct`

## File principali

- `xtecproductnav.php`
- `views/js/front.js`
- `views/css/front.css`
- `views/templates/hook/nav.tpl`

## Installazione

1. Comprimi la cartella `xtecproductnav` in ZIP.
2. Vai in **Back Office > Moduli > Module Manager > Carica un modulo**.
3. Installa il modulo.
4. Entra nella configurazione del modulo e scegli se mostrare anche il prezzo.
5. Svuota la cache di PrestaShop.

## Selettori da verificare nel tema XTec

Nel file `views/js/front.js`, se il markup del tuo tema differisce dal Classic, controlla soprattutto:

- `PRODUCT_CARD_SELECTOR`
- `PRODUCT_LINK_SELECTOR`

Sono i due punti da adattare se le miniature prodotto hanno classi custom.

## Limite noto

Se l'utente apre una pagina prodotto **direttamente** senza passare da categoria o ricerca, la navigazione non viene mostrata: questo è voluto, perché manca il contesto affidabile della lista.
