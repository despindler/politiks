# Project scripts

This directory contains explicit command-line tooling for environment checks, source acquisition, reproducible imports, publication, and deployment support.

Scripts must validate inputs, avoid printing secrets, return meaningful exit codes, and document destructive or state-changing behavior.

Current research commands include source acquisition/validation, full snapshot-plan generation, deterministic classification, benchmark evaluation, and bounded export of a human review queue. The classifier mutates only the generated ignored SQLite database; the queue exporter is read-only unless an explicit local output path is supplied.
