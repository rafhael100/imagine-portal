import unittest
from confirm_email import normalize_code


class CodeInputTests(unittest.TestCase):
    def test_exact_lowercase_code(self):
        self.assertEqual(normalize_code("ab" * 32), "ab" * 32)

    def test_uppercase_and_clipboard_whitespace(self):
        self.assertEqual(normalize_code(" \n" + "ABCD " * 16 + "\r\n"), "abcd" * 16)

    def test_bracketed_paste_markers(self):
        self.assertEqual(normalize_code("\x1b[200~" + "ab" * 32 + "\x1b[201~"), "ab" * 32)

    def test_partial_empty_or_annotated_input_is_not_a_proof(self):
        for text in ["", "a" * 63, "a" * 65, "codigo: " + "a" * 64,
                     "g" * 64, '"' + "a" * 64 + '"']:
            with self.assertRaises(ValueError):
                normalize_code(text)


if __name__ == "__main__":
    unittest.main()
