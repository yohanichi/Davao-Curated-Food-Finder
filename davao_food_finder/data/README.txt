Put your restaurant dataset CSV/Excel files in this folder. The current uploaded files are:
- ph_restos_2025(1).csv (restaurant records)
- ph_reviews_2025.csv (review rows, linked by StoreId)
CSV files are streamed row-by-row. The review CSV is aggregated by StoreId to avoid loading all reviews into memory.
Restaurant CSV columns recognized from the supplied file:
StoreId, CompleteStoreName, FoodType, AverageRating, Reviewers, City
Only records whose City contains the phrase "Davao City" are included (e.g. "davao city davao del sur").
Other optional columns such as address, price range, and description are used when present.

The app also uses osm-nearby-places.json as a locally stored Davao City OpenStreetMap snapshot for Near me searches. Refresh it manually with `node scripts/update-osm-places.js`; live searches read this snapshot and do not call Photon or Nominatim for map locations. The snapshot includes OSM attribution and its source-data timestamp. Refreshing requires internet access; the existing snapshot remains usable if an update fails.
