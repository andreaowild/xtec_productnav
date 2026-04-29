<section
  id="xtec-product-nav"
  class="xpn-block{if !$xpn_prev && !$xpn_next} xpn-block--hidden{/if}"
>
  {if $xpn_prev}
    <a
      class="xpn-fixed xpn-fixed--prev"
      href="{$xpn_prev.url|escape:'htmlall':'UTF-8'}"
      aria-label="{$xpn_prev_label|escape:'htmlall':'UTF-8'}: {$xpn_prev.name|escape:'htmlall':'UTF-8'}"
    >
      <span class="xpn-fixed__thumb">
        {if $xpn_prev.image}
          <img
            src="{$xpn_prev.image|escape:'htmlall':'UTF-8'}"
            alt="{$xpn_prev.name|escape:'htmlall':'UTF-8'}"
            loading="lazy"
            decoding="async"
          >
        {else}
          <span class="xpn-fixed__thumb-fallback">XTec</span>
        {/if}
      </span>
      <span class="xpn-fixed__panel">
        <span class="xpn-fixed__panel-inner">
          <span class="xpn-fixed__title">{$xpn_prev.name|escape:'htmlall':'UTF-8'}</span>
          {if $xpn_show_price && $xpn_prev.price}
            <span class="xpn-fixed__price">{$xpn_prev.price|escape:'htmlall':'UTF-8'}</span>
          {/if}
        </span>
      </span>
    </a>
  {/if}

  {if $xpn_next}
    <a
      class="xpn-fixed xpn-fixed--next"
      href="{$xpn_next.url|escape:'htmlall':'UTF-8'}"
      aria-label="{$xpn_next_label|escape:'htmlall':'UTF-8'}: {$xpn_next.name|escape:'htmlall':'UTF-8'}"
    >
      <span class="xpn-fixed__thumb">
        {if $xpn_next.image}
          <img
            src="{$xpn_next.image|escape:'htmlall':'UTF-8'}"
            alt="{$xpn_next.name|escape:'htmlall':'UTF-8'}"
            loading="lazy"
            decoding="async"
          >
        {else}
          <span class="xpn-fixed__thumb-fallback">XTec</span>
        {/if}
      </span>
      <span class="xpn-fixed__panel">
        <span class="xpn-fixed__panel-inner">
          <span class="xpn-fixed__title">{$xpn_next.name|escape:'htmlall':'UTF-8'}</span>
          {if $xpn_show_price && $xpn_next.price}
            <span class="xpn-fixed__price">{$xpn_next.price|escape:'htmlall':'UTF-8'}</span>
          {/if}
        </span>
      </span>
    </a>
  {/if}
</section>
