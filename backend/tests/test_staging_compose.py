"""Offline staging Compose policy regression tests, with no Docker or network access."""
import copy
import importlib.util
import pathlib
import unittest

SCRIPT=pathlib.Path(__file__).resolve().parent.parent / "scripts" / "check_staging_compose.py"
spec=importlib.util.spec_from_file_location("check_staging_compose",SCRIPT)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def valid_compose():
    return {
        "name":"aqari-pro-staging",
        "services":{
            "app":{
                "ports":[{"host_ip":"127.0.0.1","published":"18080","target":80}],
                "environment":{
                    "APP_ENV":"staging","APP_DEBUG":"false",
                    "DB_CONNECTION":"mysql","DB_HOST":"db",
                    "DB_DATABASE":"aqari_staging","DB_USERNAME":"aqari_staging",
                    "DOCUMENT_SCAN_ENABLED":"true",
                },
                "networks":{"internal":None,"outbound":None},
                "volumes":[
                    {"type":"volume","source":"staging_private","target":"/var/www/html/storage"},
                    {"type":"volume","source":"staging_clamav","target":"/var/lib/clamav"},
                ],
            },
            "db":{
                "networks":{"internal":None},
                "volumes":[
                    {"type":"volume","source":"staging_mysql","target":"/var/lib/mysql"},
                ],
            },
        },
        "networks":{"internal":{"internal":True},"outbound":{}},
        "volumes":{
            "staging_private":{"name":"aqari-pro-staging_staging_private"},
            "staging_clamav":{"name":"aqari-pro-staging_staging_clamav"},
            "staging_mysql":{"name":"aqari-pro-staging_staging_mysql"},
        },
    }


class StagingComposeGuardTest(unittest.TestCase):
    def test_valid_isolated_compose_is_accepted(self):
        self.assertIsNone(module.validate(valid_compose()))

    def test_production_volume_or_external_volume_is_rejected(self):
        for modify in (
            lambda x: x["volumes"]["staging_mysql"].update(name="aqari_mysql_data"),
            lambda x: x["volumes"]["staging_mysql"].update(external=True),
            lambda x: x["services"]["db"]["volumes"][0].update(source="mysql_data"),
            lambda x: x["services"]["app"]["volumes"][0].update(type="bind"),
        ):
            config=copy.deepcopy(valid_compose())
            modify(config)
            with self.assertRaises(ValueError):
                module.validate(config)

    def test_database_exposure_and_foreign_networks_are_rejected(self):
        for modify in (
            lambda x: x["services"]["db"].update(ports=[{"published":"3306"}]),
            lambda x: x["services"]["db"]["networks"].update(outbound=None),
            lambda x: x["services"]["app"]["networks"].update(public=None),
            lambda x: x["networks"]["internal"].update(internal=False),
        ):
            config=copy.deepcopy(valid_compose())
            modify(config)
            with self.assertRaises(ValueError):
                module.validate(config)

    def test_wrong_app_port_db_identity_or_disabled_scan_is_rejected(self):
        for modify in (
            lambda x: x["services"]["app"]["ports"][0].update(target=443),
            lambda x: x["services"]["app"]["ports"][0].update(host_ip="0.0.0.0"),
            lambda x: x["services"]["app"]["environment"].update(DB_HOST="production-db"),
            lambda x: x["services"]["app"]["environment"].update(DOCUMENT_SCAN_ENABLED="false"),
        ):
            config=copy.deepcopy(valid_compose())
            modify(config)
            with self.assertRaises(ValueError):
                module.validate(config)


if __name__=="__main__":
    unittest.main()
