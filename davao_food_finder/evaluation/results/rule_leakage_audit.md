# Synonym and anti-polysemy rule leakage audit

| Type | Rule | Keyword regex | Replacement | Test-split overlap |
|---|---|---|---|---|
| synonym | bisaya_kan_anan | `/\b(kan[- ]?anan\|karinderya\|carenderia\|turo[- ]?turo)\b/iu` | carinderia filipino affordable | D008 |
| synonym | tagalog_kape | `/\b(kapehan\|kape)\b/iu` | coffee cafe | none |
| synonym | bisaya_sinugba | `/\b(sinugba\|sugba\|inihaw\|inasalan\|ihawan\|ihaw[- ]?ihaw)\b/iu` | bbq grilled liempo | D009 |
| synonym | bisaya_sutukil | `/\b(sutukil\|kinilaw)\b/iu` | seafood filipino | D009 |
| synonym | local_manokan | `/\b(manokan\|litson\s+manok\|lechon\s+manok)\b/iu` | chicken roasted chicken | none |
| synonym | bisaya_painitan | `/\b(painitan\|pamahaw)\b/iu` | breakfast porridge lugaw coffee early morning | none |
| synonym | tagalog_panghimagas | `/\b(panghimagas\|matamis)\b/iu` | desserts cakes sweet | none |
| synonym | filipino_sisigan | `/\b(sisigan)\b/iu` | sisig filipino | none |
| synonym | filipino_silogan | `/\b(silogan\|tapsilogan)\b/iu` | silog tapsilog filipino | none |
| synonym | filipino_paresan | `/\b(paresan\|mamihan)\b/iu` | pares mami noodles filipino | none |
| synonym | filipino_lugawan | `/\b(lugawan\|gotohan)\b/iu` | lugaw goto porridge | none |
| synonym | filipino_bulaloan | `/\b(bulaloan\|bulalohan)\b/iu` | bulalo soup filipino | none |
| synonym | filipino_lomihan | `/\b(lomihan\|batchoyan)\b/iu` | lomi batchoy noodles | none |
| synonym | filipino_lechonan | `/\b(lechonan)\b/iu` | lechon pork filipino | none |
| synonym | filipino_kambingan | `/\b(kambingan)\b/iu` | kambing goat filipino | none |
| synonym | typo_samgyup | `/\b(sangyup\|samgyeopsal\|samgyup\s*sal\|samgy)\b/iu` | samgyupsal korean | D010 |
| synonym | typo_shawarma | `/\b(shwarma\|shawerma\|syawarma)\b/iu` | shawarma middle eastern | none |
| synonym | typo_biryani | `/\b(briyani\|biriyani\|beryani)\b/iu` | biryani indian | none |
| synonym | synonym_ramyun | `/\b(ramyun\|ramyeon)\b/iu` | ramen noodles | D010 |
| synonym | typo_coffee | `/\b(kapeng\s+barako\|espreso\|capuccino\|cappucino)\b/iu` | coffee espresso cafe | none |
| synonym | spelling_seafood | `/\b(sea\s+food)\b/iu` | seafood | none |
| anti_polysemy_brand_trap | polysemy_green_brand_not_salad | `/\b(greenwich\|green\s+coffee\|green\s+bread)\b/iu` |  | none |
| anti_polysemy_brand_trap | polysemy_24_brand_not_24h | `/\b24\s+chicken\b/iu` |  | none |
| anti_polysemy_brand_trap | polysemy_tokyo_waffle_not_japanese | `/\btokyo\s+waffle\b/iu` |  | none |
| anti_polysemy_brand_trap | polysemy_durian_burger_not_pasalubong | `/\bdurian\s+dub\b/iu` |  | none |
| anti_polysemy_brand_trap | category_confusion_milktea_not_coffee | `/\b(coffee\|cafe\|café\|espresso)\b/iu` |  | Q032, D005, D012, PC003, MT002, S010 |
