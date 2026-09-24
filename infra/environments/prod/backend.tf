# state は bootstrap で作ったバケットに置く。
# prefix でバケット内を分けるので、bootstrap の state とは混ざらない。
#
# backend の設定はプロバイダより先に読まれるため var や local は使えない。
# バケット名はリテラルで書く。
terraform {
  backend "gcs" {
    bucket = "my-project-book-509215-tfstate"
    prefix = "prod"
  }
}
