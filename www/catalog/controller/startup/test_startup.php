<?php

class TestSession
{
    public static $static_data = array();
    public static $session_id = 0;

    public function & __get($name)
    {
        if ($name === 'data') {
            return self::$static_data;
        }
    }

    public function __construct()
    {
        if (self::$session_id === 0) {
            self::$session_id = bin2hex(openssl_random_pseudo_bytes(16));
        }
    }

    public function getId() {
        return self::$session_id;
    }

}

class ControllerStartupTestStartup extends Controller {
    public function index() {

        // Test Session
        $this->registry->set('session', new TestSession());

        // Store
        if ($this->request->server['HTTPS']) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "store WHERE REPLACE(`ssl`, 'www.', '') = '" . $this->db->escape('https://' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . rtrim(dirname($_SERVER['PHP_SELF']), '/.\\') . '/') . "'");
        } else {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "store WHERE REPLACE(`url`, 'www.', '') = '" . $this->db->escape('http://' . str_replace('www.', '', $_SERVER['HTTP_HOST']) . rtrim(dirname($_SERVER['PHP_SELF']), '/.\\') . '/') . "'");
        }

        if (isset($this->request->get['store_id'])) {
            $this->config->set('config_store_id', $this->request->get['store_id']);
        } else if ($query->num_rows) {
            $this->config->set('config_store_id', $query->row['store_id']);
        } else {
            $this->config->set('config_store_id', 0);
        }

        if (!$query->num_rows) {
            $this->config->set('config_url', HTTP_SERVER);
        }

        // Settings
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' OR store_id = '" . (int)$this->config->get('config_store_id') . "' ORDER BY store_id ASC");

        foreach ($query->rows as $result) {
            if (!$result['serialized']) {
                $this->config->set($result['key'], $result['value']);
            } else {
                $this->config->set($result['key'], json_decode($result['value'], true));
            }
        }

        // Language
        $code = '';

        $this->load->model('localisation/language');

        $languages = $this->model_localisation_language->getLanguages();

        if (isset($this->session->data['language'])) {
            $code = $this->session->data['language'];
        }

        if (isset($this->request->cookie['language']) && !array_key_exists($code, $languages)) {
            $code = $this->request->cookie['language'];
        }

        // Language Detection
        if (!empty($this->request->server['HTTP_ACCEPT_LANGUAGE']) && !array_key_exists($code, $languages)) {
            $browser_languages = explode(',', $this->request->server['HTTP_ACCEPT_LANGUAGE']);

            foreach ($browser_languages as $browser_language) {
                if (array_key_exists(strtolower($browser_language), $languages)) {
                    $code = strtolower($browser_language);

                    break;
                }
            }
        }

        if (!array_key_exists($code, $languages)) {
            $code = $this->config->get('config_language');
        }

        if (!isset($this->session->data['language']) || $this->session->data['language'] != $code) {
            $this->session->data['language'] = $code;
        }

        // Overwrite the default language object
        $language = new Language($code);
        $language->load($code);

        $this->registry->set('language', $language);

        // Set the config language_id
        $this->config->set('config_language_id', $languages[$code]['language_id']);

        // Customer
        $customer = new Cart\Customer($this->registry);
        $this->registry->set('customer', $customer);

        // Customer Group
        if ($this->customer->isLogged()) {
            $this->config->set('config_customer_group_id', $this->customer->getGroupId());
        } elseif (isset($this->session->data['customer']) && isset($this->session->data['customer']['customer_group_id'])) {
            // For API calls
            $this->config->set('config_customer_group_id', $this->session->data['customer']['customer_group_id']);
        } elseif (isset($this->session->data['guest']) && isset($this->session->data['guest']['customer_group_id'])) {
            $this->config->set('config_customer_group_id', $this->session->data['guest']['customer_group_id']);
        }

        // Tracking Code
        if (isset($this->request->get['tracking'])) {
            setcookie('tracking', $this->request->get['tracking'], time() + 3600 * 24 * 1000, '/');

            $this->db->query("UPDATE `" . DB_PREFIX . "marketing` SET clicks = (clicks + 1) WHERE code = '" . $this->db->escape($this->request->get['tracking']) . "'");
        }

        // Affiliate
        $this->registry->set('affiliate', new Cart\Affiliate($this->registry));

        // Currency
        $code = '';

        $this->load->model('localisation/currency');

        $currencies = $this->model_localisation_currency->getCurrencies();

        if (isset($this->session->data['currency'])) {
            $code = $this->session->data['currency'];
        }

        if (isset($this->request->cookie['currency']) && !array_key_exists($code, $currencies)) {
            $code = $this->request->cookie['currency'];
        }

        if (!array_key_exists($code, $currencies)) {
            $code = $this->config->get('config_currency');
        }

        if (!isset($this->session->data['currency']) || $this->session->data['currency'] != $code) {
            $this->session->data['currency'] = $code;
        }

        $this->registry->set('currency', new Cart\Currency($this->registry));

        // Tax
        $this->registry->set('tax', new Cart\Tax($this->registry));

        if (isset($this->session->data['shipping_address'])) {
            $this->tax->setShippingAddress($this->session->data['shipping_address']['country_id'], $this->session->data['shipping_address']['zone_id']);
        } elseif ($this->config->get('config_tax_default') == 'shipping') {
            $this->tax->setShippingAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));
        }

        if (isset($this->session->data['payment_address'])) {
            $this->tax->setPaymentAddress($this->session->data['payment_address']['country_id'], $this->session->data['payment_address']['zone_id']);
        } elseif ($this->config->get('config_tax_default') == 'payment') {
            $this->tax->setPaymentAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));
        }

        $this->tax->setStoreAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));

        // Weight
        $this->registry->set('weight', new Cart\Weight($this->registry));

        // Length
        $this->registry->set('length', new Cart\Length($this->registry));

        // Cart
        $this->registry->set('cart', new Cart\Cart($this->registry));

        // Encryption
        $this->registry->set('encryption', new Encryption($this->config->get('config_encryption')));

        // OpenBay Pro
        $this->registry->set('openbay', new Openbay($this->registry));
    }
}
