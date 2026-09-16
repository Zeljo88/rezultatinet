# Match sitemap routability

## Root cause fixed on 2026-09-16

The match sitemap generated a URL from each fixture's attached home/away team names or slugs. The public match route then independently resolved those URL slugs with a global team lookup and selected the first matching team row. Production evidence showed two related data conditions: many fixture teams have no stored slug, and some club names are duplicated across team records. The sitemap fallback uses `Str::slug()` to generate a URL, while the legacy route fallback only lowercases names and removes spaces/periods. Names containing punctuation such as `H&H`, `O'Higgins`, or `NJ/NY` therefore could not be resolved; duplicated names such as Bolívar, Platense, Aurora, Santa Cruz, and Linense resolved to an earlier, different team ID. In both cases no fixture existed for the route-resolved team-ID pair and date, producing the audited 17 HTTP 404 responses.

The sitemap now applies the same deterministic team resolution rules as the public route and emits a match only when the resolved home/away IDs and date correspond to fixture data in the sitemap cohort. This is data-driven: there is no denylist of the 17 audited URLs, so future duplicate or missing team mappings are excluded by the same routability check.
