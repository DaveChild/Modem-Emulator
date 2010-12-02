<?php

    /*

        Modem Emulator / Throughput Throttling Proxy
        http://code.google.com/p/modem-emulator/
        http://groups.google.com/group/modem-emulator/

        Released under New BSD license
        http://www.opensource.org/licenses/bsd-license.php

        The emulator is designed to demonstrate how slowly a site may
        load over dial-up. It errs on the side of caution (so it usually
        operates a little more slowly than the speed set).

        It fetches a file, then processes the file to rewrite URLs within it.
        Output is either handled by mb_ functions (or string functions if no
        mb_ functions available) or temp files.

        Speeds are highly variable, and are affected by connection times etc.
        
        As I've been asked this a few times: 3G runs at 128kbit/s to 2Mbit/s.

        Note that none of the file functions have their errors suppressed. It
        is highly recommended you precede these with '@' in production.

    */

    class ModemEmulator {

        /* Settings */
        private $strPublicEmulatorDomain = 'example.com'; // Domain hosting the emulator
        private $strPublicEmulatorURL = 'http://www.example.com/modem-emulator.php'; // URL of emulator
        // Make sure the URL above has no # symbol

        private $blnAllowCaching = false; // Turn on to allow browser to cache items.

        private $blnUseCurl = true; // CURL preferred, set to false to try PHP file functions

        private $blnUseTempFiles = false; // Uses mb_ functions by default - if not working, try this
        private $strTempDir = '/home/user/temp/'; // Needed if above is set to true, must be writable

        private $arrBlockedURLs = array(); // Add URLs or domains to this array to block them.

        /* Class Vars */
        /* Do not edit below this line unless you know what you're doing. */

        private $strURL = ''; // URL to fetch.
        private $strBaseHref = ''; // Required for images, scripts and styles to load
        private $strMimeType = ''; // Mime type of URL, needed for replacements
        private $strURLContents = ''; // Var holds whatever was grabbed from the URL
        private $strTempFile = ''; // Temp file written by server and deleted after run
        private $intSpeed = 56000; // Bits per second - set to 56k modem by default. 

        /**
         * Constructor.
         *
         * @param string  $strURL    URL to fetch
         * @param integer $intSpeed  Speed in bytes to run at
         * @return void
         */
        public function __construct($strURL, $intSpeed = 56000) {
            // We check to ensure the emulator is being accessed from within the domain
            // set above, to prevent people using an IP address to loop the emulator.
            if (strpos($_SERVER["HTTP_HOST"], $this->strPublicEmulatorDomain) === false) {
                // User is not running emulator from specified domain.
                $this->handle_error('Emulator must be run from ' . $this->strPublicEmulatorDomain . '.');
            }
            $this->set_url($strURL);
            $this->set_speed($intSpeed);
        }

        /**
         * Set URL, base href and temp file info. Check URL not blocked.
         * @param   strSetURL     URL to fetch
         */
        public function set_url($strSetURL) {
            // Validate
            if (strpos($strSetURL, $this->strPublicEmulatorDomain) !== false) {
                // User is requesting a URL within the same domain. This
                // can result in looping, so is disabled by default.
                $this->handle_error('URLs within ' . $this->strPublicEmulatorDomain . ' are disabled.');
            }
            // Check not blocked
            for ($i = 0, $max = count($this->arrBlockedURLs); $i < $max; $i++) {
                if (strpos($strSetURL, $this->arrBlockedURLs[$i]) !== false) {
                    $this->handle_error('Access to that URL through the emulator has been blocked.');
                }
            }

            // Set URL
            $this->strURL = $strSetURL;

            // Check for "http"
            $this->strURL = str_replace('http://https://', 'https://', str_replace('http://http://', 'http://', 'http://' . $this->strURL));

            // If just domain entered, trailing slash may be missing and should be added.
            if (substr_count($this->strURL, '/') < 3) {
                $this->strURL = $this->strURL . '/';
            }

            // Set file we'll be writing to
            $this->strTempFile = $this->strTempDir . md5($this->strURL) . '.cache';

            // We may need a base href. To start, we set it based on the given URL
            $this->strBaseHref = substr($this->strURL, 0, strrpos($this->strURL, '/'));
        }

        /**
         * Set speed in bytes that emulator should run at.
         * @param integer $intSpeed  Speed in bytes to run at
         */
        private function set_speed($intSetSpeed) {
            // This is set in bits per second, later divided by 8 for
            // bytes per second.
            $this->intSpeed = $intSetSpeed;
        }

        /**
         * Run the emulation - fetch page, update URLs of content and send back to user
         */
        public function run() {
            // Fetch remote file
            $this->fetch_file();
            // Replace content in file depending on mime type
            if (strpos($this->strMimeType, 'text') !== false) {
                $this->rewrite_urls();
            }
            // Output file
            $this->output_file();
        }

        /**
         * Convenience function to fetch file via curl or various file functions
         */
        private function fetch_file() {
            if ($this->blnUseCurl) {
                $fc = curl_init();
                curl_setopt($fc, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($fc, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($fc, CURLOPT_MAXREDIRS, 10);
                curl_setopt($fc, CURLOPT_URL, $this->strURL);
                $tmpUrlContent = curl_exec($fc);
                $header = curl_getinfo($fc);
                $this->strMimeType = 'Content-Type:' . $header['content_type'];
                if (!$tmpUrlContent) {
                    $this->handle_error('CURL unable to fetch URL (' . $header['redirect_count'] . ' redirects).');
                }
                curl_close($fc);
            } else {
                // Try fetching file with fopen
                $fp = fopen($this->strURL, "r"); 
                if (!$fp) {
                    // fopen no good. Try file_get_contents
                    $tmpUrlContent == file_get_contents($this->strURL);
                    if (!$tmpUrlContent) {
                        $this->handle_error('Unable to fetch URL.');
                    }
                } else {
                    $tmpUrlContent = '';
                    while (!feof($fp)) {
                        $tmpUrlContent .= fread($fp, 8192);
                    }
                    fclose($fp);
                }
                $arrHeaders = $http_response_header;
                for ($i = 0, $max = count($arrHeaders); $i < $max; $i++) {
                    if (substr($arrHeaders[$i], 0, 13) == "Content-Type:") {
                        // This is the mime type
                        $this->strMimeType = $arrHeaders[$i];
                    }
                }
            }
            $this->strURLContents = $tmpUrlContent;
        }

        /**
         * Function processes page to rewrite all URLs so that images and scripts are loaded through the emulator as well.
         */
        private function rewrite_urls() {
            // We're dealing with a text file. First, parse out a base href if present
            if (strpos($this->strURLContents, '<base ') !== false) {
                // Looks like there's a base tag. Fetch the URL.
                preg_match('#<base[^>]*href="([^"]+)"[^>]*/?>#i', $this->strURLContents, $matches);
                $this->strBaseHref = $matches[1];
                // Trailing slash ... had some problems working out whether to remove or not. Or even add.
                //if (substr($this->strBaseHref, -1, 1) == '/') {
                //    $this->strBaseHref =substr($this->strBaseHref, 0, -1);
                //}
            }

            // Add base to all relative URLs
            $this->strURLContents = preg_replace('/(href|src)=("|\')(?!http:)(?!https:)([^"]+)/im', '$1=$2' . $this->strBaseHref . '$3', $this->strURLContents); // Yeehah, dual negative lookahead assertion goodness

            // CSS
            $this->strURLContents = preg_replace('/url ?\((\'|")?/im', 'url($1' . $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=' . $this->strBaseHref . '/', $this->strURLContents);
            $this->strURLContents = preg_replace('/@import(\(| )"/im', '@import$1"' . $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=' . $this->strBaseHref . '/', $this->strURLContents);
           
            // Images, etc
            $this->strURLContents = preg_replace('/src=("|\')/im', 'src=$1' . $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=' . $this->strBaseHref . '/', $this->strURLContents);
            $this->strURLContents = preg_replace('/background=("|\')/im', 'background=$1' . $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=' . $this->strBaseHref . '/', $this->strURLContents);

            // Bastard link/a tags
            $this->strURLContents = preg_replace('/href=("|\')/im', 'href=$1' . $this->strBaseHref . '/', $this->strURLContents); // Sorts out most links

            // We need anything not in an <a tag to be rewritten
            $this->strURLContents = preg_replace('/(<[^a]([^>]*)href=("|\'))([^"]*)"([^>]*)>/im', '$1' . $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=\\4"\\5>', $this->strURLContents);
    
            // Tidy up - replace some common mistakes
            $this->strURLContents = str_replace($this->strBaseHref . '/http://', 'http://', $this->strURLContents); 
            $this->strURLContents = str_replace($this->strBaseHref . '//', $this->strBaseHref . '/', $this->strURLContents); 
            $this->strURLContents = str_replace($this->strBaseHref . '/' . $this->strBaseHref . '/', $this->strBaseHref . '/', $this->strURLContents);

            // Encode URLs
            $this->strURLContents = preg_replace_callback('#' . $this->strPublicEmulatorURL . '\?speed=' . $this->intSpeed . '&url=([^"\'\)]+)#i', array(&$this, 'encode_urls'), $this->strURLContents);
            // The above line and following function do a basic regex search for variables set above, and use them to url encode the url sent back the emulator.
            // The "array(&$this, 'encode_urls')" bit is how you use a class function as a callback for preg_replace_callback
            // URLs with brackets in will fail. Hopefully not too many of them!
        }

        /**
         * Encode URLs (callback function)
         */
        private function encode_urls($matches) {
            return $this->strPublicEmulatorURL . '?speed=' . $this->intSpeed . '&url=' . urlencode($matches[1]);
        }

        /**
         * Output URL contents to user
         */
        private function output_file() {
            // Send mime type header
            header($this->strMimeType);
            // If caching not allowed, expire contents.
            if (!$this->blnAllowCaching) {
                header("Cache-Control: no-cache, must-revalidate");
                header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            }
            if ($this->blnUseTempFiles) {
                // Using temp files.
                $this->write_file(); // Write string to file
                $this->send_file(); // Read and delete file
            } else {
                $this->output_string(); // Use multi-byte functions
            }
        }

        /**
         * Send processed URL contents to user using mb_ functions, or revert to string functions if no mb_
         */
        private function output_string() {
            if (function_exists('mb_strlen')) {
                $intFileLength = mb_strlen($this->strURLContents, '8bit');
            } else {
                $intFileLength = strlen($this->strURLContents);
            }
            $i = 0;
            while ($i <= $intFileLength) {
                if (function_exists('mb_substr')) {
                    echo mb_substr($this->strURLContents, $i, round($this->intSpeed / 8), '8bit');
                } else {
                    echo substr($this->strURLContents, $i, round($this->intSpeed / 8));
                }
                $i += round($this->intSpeed / 8);
                flush();
                sleep(1);
            }
        }

        /**
         * Write URL contents to temp file
         */
        private function write_file() {
            $fp = fopen($this->strTempFile, 'a+');
            fwrite($fp, $this->strURLContents);
            fclose($fp);
        }

        /**
         * Sends file and deletes it once complete.
         */
        private function send_file() {
            $fp = fopen($this->strTempFile, 'r');
            while(!feof($fp)) {
                $strTmpLine = fread($fp, round($this->intSpeed / 8));
                echo $strTmpLine;
                flush();
                sleep(1);
            }
            fclose($fp);
            unlink($this->strTempFile);
        }

        /**
         * Manage error messages
         * @param   strErrorMessage     Error message
         */
        private function handle_error($strErrorMessage) {
            // Function is included for convenience, in case author wishes 
            // to handle errors in a specific fashion.
            die($strErrorMessage);
        }
    }

?>