"""Validation indépendante du contrat, hors réseau et sans accès à la base."""
import copy
import json
import unittest
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = json.loads((ROOT / "docs/openapi.json").read_text())
META = json.loads((ROOT / "docs/schemas/openapi-3.1.schema.json").read_text())


def resolve(pointer):
    if not pointer.startswith("#/"):
        raise AssertionError("Le contrat doit rester autonome : " + pointer)
    value = DOCUMENT
    for item in pointer[2:].split("/"):
        value = value[item.replace("~1", "/").replace("~0", "~")]
    return value


class OpenApiContractTest(unittest.TestCase):
    def test_official_openapi_schema(self):
        Draft202012Validator(META, format_checker=FormatChecker()).validate(DOCUMENT)

    def test_each_component_is_valid_json_schema(self):
        for name, schema in DOCUMENT["components"]["schemas"].items():
            with self.subTest(schema=name):
                Draft202012Validator.check_schema(schema)

    def test_all_local_references_resolve(self):
        def visit(node):
            if isinstance(node, dict):
                if "$ref" in node:
                    self.assertIsNotNone(resolve(node["$ref"]))
                for value in node.values():
                    visit(value)
            elif isinstance(node, list):
                for value in node:
                    visit(value)
        visit(DOCUMENT)

    def test_validator_rejects_broken_operations_and_schemas(self):
        invalid = copy.deepcopy(DOCUMENT)
        del invalid["info"]["title"]
        self.assertFalse(Draft202012Validator(META).is_valid(invalid))
        invalid = copy.deepcopy(DOCUMENT)
        invalid["paths"]["/health"]["get"]["responses"] = {"not-a-status": {}}
        self.assertFalse(Draft202012Validator(META).is_valid(invalid))
        with self.assertRaises(Exception):
            Draft202012Validator.check_schema({"type": "invented-type"})


if __name__ == "__main__":
    unittest.main(verbosity=2)
