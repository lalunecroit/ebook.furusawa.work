terraform {
  backend "gcs" {
    bucket = "my-project-book-509215-tfstate"
    prefix = "bootstrap"
  }
}
