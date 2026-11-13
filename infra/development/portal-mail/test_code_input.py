import unittest
from confirm_email import normalize_code


class CodeInputTests(unittest.TestCase):
    def test_exact_six_digit_code_preserves_leading_zero(self):
        self.assertEqual(normalize_code("023456"), "023456")

    def test_clipboard_whitespace(self):
        self.assertEqual(normalize_code(" \n023 456\r\n"), "023456")

    def test_bracketed_paste_markers(self):
        self.assertEqual(normalize_code("\x1b[200~023456\x1b[201~"), "023456")

    def test_partial_empty_or_annotated_input_is_not_a_proof(self):
        for text in ["", "12345", "1234567", "codigo: 123456",
                     "a" * 64, "１２３４５６", '"123456"']:
            with self.assertRaises(ValueError):
                normalize_code(text)


if __name__ == "__main__":
    unittest.main()
