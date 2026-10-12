output "cdn_domain" {
  description = "CloudFront domain name that serves the media files."
  value       = aws_cloudfront_distribution.media.domain_name
}
