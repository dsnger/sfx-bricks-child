<?php

declare(strict_types=1);

// Deliberately in the GLOBAL namespace (no `namespace` statement): Controller.php
// declares `public static ?\WP_Error $errors` with a leading backslash, i.e. it
// means WordPress core's real global \WP_Error, not a namespaced stand-in.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, array<int, string>> */
        private array $errors = [];

        public function add(string $code, string $message = ''): void
        {
            $this->errors[$code][] = $message;
        }

        /** @return array<int, string> */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }

        /** @return array<int, string> */
        public function get_error_messages(): array
        {
            $messages = [];
            foreach ($this->errors as $code_messages) {
                $messages = array_merge($messages, $code_messages);
            }

            return $messages;
        }
    }
}
