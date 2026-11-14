# Dataset provenance and statistics

| Field | Value |
|---|---|
| source info | {"dataset_name":"Philippine Cities Food Panda Resto Reviews","author":"BwandoWando (Kaggle username: bwandowando)","url":"https://www.kaggle.com/datasets/bwandowando/food-panda-restaurant-reviews","license":"CC BY-NC-SA 4.0","download_date":"not stated on the public Kaggle page and not independently recorded in this repository. Do not treat the content update date as the download date."} |
| input file | ph_restos_2025.csv |
| input sha256 | bdd741dc8d83d3ba119e7a99ea6a69c2228aa0d458389a09543576a0cef8caaa |
| columns | ["StoreId","CompleteStoreName","FoodType","AverageRating","Reviewers","City"] |
| davao filter rule | isDavaoCity(): find the City/municipality/locality field; include only when preg_match('/\bdavao\s+city\b/i', value) matches. |
| excluded by filter | Rows whose city value does not contain the contiguous words “Davao City” (for example, Tagum City, Panabo City, Davao del Sur without “Davao City”, and other Davao Region localities). |
| rows before filtering | 12409 |
| rows after filtering | 1172 |
| rating zero count | 253 |
| rating zero share | 0.21587030716724 |
| reviewers zero count | 253 |
| reviewers zero share | 0.21587030716724 |
| rating statistics including zero | {"count":1172,"mean":3.8160409556314114,"median":4.9,"sd_sample":2.0297906043611205,"min":0,"q1":4.4,"q3":5,"max":5} |
| rating statistics excluding zero | {"count":919,"mean":4.866594124047894,"median":5,"sd_sample":0.37060340541456044,"min":1.4,"q1":4.9,"q3":5,"max":5} |
| raw rating zero system treatment | AverageRating=0 is treated as missing by loadDavaoPlaces (only values greater than 0 are accepted); review aggregates may supply a fallback rating. This descriptive report includes raw zeroes in the “including zero” series. |
