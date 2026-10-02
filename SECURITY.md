# Security

Clinic Flow processes health information of South African patients and is subject to POPIA.

- Report a vulnerability privately to the tech lead (Mayura Consultancy Services). Do not open a public issue.
- Secrets live in GitHub Actions secrets and the AWS secrets store, never in the repository.
- Production data never leaves AWS af-south-1 and is never copied to developer machines.
- Support access to a provider's data is time-boxed, approved by that provider and written to its audit log.
