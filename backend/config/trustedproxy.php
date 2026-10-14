<?php

return [

    /*
    | Proxies in front of the API whose X-Forwarded-For header is believed, so rate limits and the
    | audit log see each visitor's own address instead of the proxy's. Empty trusts none (direct
    | traffic). "REMOTE_ADDR" trusts only the proxy that connects to the server, which suits one
    | hosting proxy such as Railway's or Render's. "*" trusts every hop and lets a visitor fake their
    | address when the proxy adds to the header instead of replacing it. A comma list of IPs or
    | ranges also works.
    */
    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
